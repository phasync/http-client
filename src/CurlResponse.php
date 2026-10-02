<?php

namespace phasync\HttpClient;

use phasync;
use phasync\Psr\ComposableStream;
use phasync\Services\CurlMulti;
use phasync\Util\Queue;
use phasync\Util\QueueInterface;
use phasync\Util\Synchronized;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The PSR-7 response of an {@see HttpClient} request, filled in by a cURL transfer.
 *
 * {@see HttpClient::sendRequest()} returns a response whose headers have arrived. The convenience
 * methods `get()`, `post()`, `put()` and `request()` return it before the transfer has started, so
 * that several requests can run together: the first call that needs the status line or the headers
 * (`getStatusCode()`, `getHeader()`, `withHeader()` and the like) or the first read of the body
 * starts every pending transfer and waits for the headers, suspending only the calling coroutine.
 * `getBody()` itself returns at once. Outside a coroutine that wait runs the transfers to the end.
 * The body is read once: reading it again returns nothing.
 *
 * A transfer that fails before the headers have arrived (connection refused, DNS failure, timeout,
 * too many redirects, an invalid URL) throws from every accessor, and from the body read, a
 * {@see NetworkException}, a {@see RequestException} or a {@see ClientException}, with cURL's error
 * number as its code. A transfer that fails after the headers keeps its status, reason phrase and
 * headers, and throws the exception when the body read reaches the point of the failure. A 4xx or
 * 5xx status is not a failure.
 *
 * The `with*()` methods wait for the headers and return a copy that shares the body stream.
 *
 * ```php
 * $response = $client->get('https://example.com/report.csv');
 *
 * if (200 === $response->getStatusCode()) {
 *     $body = $response->getBody();
 *     while (!$body->eof()) {
 *         echo $body->read(8192);
 *     }
 * }
 * ```
 *
 * @see HttpClient::request
 * @see HttpClientOptions
 * @see NetworkException
 */
class CurlResponse implements ResponseInterface
{
    public readonly HttpClientOptions $options;
    public readonly string $url;
    public readonly string $method;
    private \CurlHandle $curl;
    private array $responseHeaders   = [];
    private string $buffer           = '';
    private ?int $downloadSize       = null;
    private ?int $downloaded         = null;
    private ?int $uploadSize         = null;
    private ?int $uploaded           = null;
    private bool $done               = false;
    private ?StreamInterface $body   = null;
    private bool $haveHeaders        = false;
    private ?\Fiber $transfer        = null;
    private ?string $protocolVersion = null;
    private ?int $statusCode         = null;
    private ?string $reasonPhrase    = null;
    private ?int $errorNumber        = null;
    private ?string $errorMessage    = null;
    private RequestInterface $request;

    /**
     * CurlResponse classes that need to start fetching. This is used to enable
     * the HttpClient to be used outside of phasync while still supporting
     * concurrent fetching.
     *
     * @var QueueInterface<CurlResponse>|null
     */
    private static ?QueueInterface $fetchQueue = null;

    /**
     * Queue a transfer. {@see HttpClient} creates the responses; call that instead.
     *
     * @internal
     */
    public function __construct(RequestInterface $request, HttpClientOptions $options)
    {
        $method      = $request->getMethod();
        $url         = (string) $request->getUri();
        $requestData = $request->getBody();
        if (null === self::$fetchQueue) {
            Synchronized::run(self::class, static function () {
                if (null === self::$fetchQueue) {
                    self::$fetchQueue = new Queue();
                }
            });
        }
        $this->curl = \curl_init($url);
        \curl_setopt($this->curl, \CURLOPT_NOPROGRESS, false);
        switch (\strtoupper($method)) {
            case 'GET':
                // For GET requests, if there is any request data, append it to the URL
                $queryString = null;
                if (
                    null === $requestData
                    || '' === $requestData
                    || $requestData instanceof StreamInterface && 0 === $requestData->getSize()
                ) {
                    // Empty request data is ignored
                } elseif (\is_array($requestData)) {
                    $queryString = \http_build_query($requestData);
                } elseif (\is_string($requestData) || $requestData instanceof \Stringable) {
                    $queryString = (string) $requestData;
                } else {
                    throw new \InvalidArgumentException("Request data of type '" . \get_debug_type($requestData) . "' is not supported for GET requests");
                }
                if (null !== $queryString) {
                    if (\str_contains($url, '?')) {
                        $url .= '&' . $queryString;
                    } else {
                        $url .= '?' . $queryString;
                    }
                }
                \curl_setopt($this->curl, \CURLOPT_URL, $url);
                break;
            case 'POST':
                // For POST requests, set the request data as the POSTFIELDS option
                \curl_setopt($this->curl, \CURLOPT_POST, true);
                \curl_setopt($this->curl, \CURLOPT_POSTFIELDS, $requestData);
                break;
            default:
                // Also handles 'PUT': CURLOPT_CUSTOMREQUEST plus CURLOPT_POSTFIELDS sends the
                // given body with any method. CURLOPT_PUT is a different, incompatible mode
                // that reads the body from CURLOPT_INFILE and ignores CURLOPT_POSTFIELDS.
                // For other request methods, set the request data as the custom request body
                \curl_setopt($this->curl, \CURLOPT_CUSTOMREQUEST, $method);
                \curl_setopt($this->curl, \CURLOPT_POSTFIELDS, $requestData);
                break;
        }
        $this->request = $request;
        $this->options = $options;
        $this->url     = $url;
        $this->method  = $method;
        $this->applyOptions($options);
        \curl_setopt($this->curl, \CURLOPT_HEADERFUNCTION, $this->curlHeaderFunction(...));
        \curl_setopt($this->curl, \CURLOPT_WRITEFUNCTION, $this->curlWriteFunction(...));
        \curl_setopt($this->curl, \CURLOPT_XFERINFOFUNCTION, $this->curlXferInfoFunction(...));
        $this->body = new ComposableStream(
            readFunction: function (int $length) {
                self::runQueue();

                while ('' === $this->buffer && !$this->done) {
                    $this->awaitChange();
                }
                if ('' === $this->buffer) {
                    if (null !== $this->errorNumber) {
                        throw $this->failure();
                    }

                    return null;
                }
                $chunk        = \substr($this->buffer, 0, $length);
                $this->buffer = \substr($this->buffer, \strlen($chunk));

                return $chunk;
            },
            getSizeFunction: $this->getDownloadSize()
        );

        self::$fetchQueue->enqueue($this);
    }

    private function goCurl(): void
    {
        /*
         * Start the curl handle via the event loop, and return when it is
         * done.
         */
        CurlMulti::await($this->curl);
        if (0 !== ($errorNumber = \curl_errno($this->curl))) {
            $this->errorNumber  = $errorNumber;
            $this->errorMessage = \curl_error($this->curl);
        }
        $this->done = true;
        \phasync::raiseFlag($this);
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $this->waitForHeaders();

        $c               = clone $this;
        $c->statusCode   = $code;
        $c->reasonPhrase = $reasonPhrase;

        return $c;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        $this->waitForHeaders();

        $c                  = clone $this;
        $c->protocolVersion = $version;

        return $c;
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $this->waitForHeaders();

        $c                                      = clone $this;
        $c->responseHeaders[\strtolower($name)] = \is_array($value) ? $value : [(string) $value];

        return $c;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $this->waitForHeaders();

        $c    = clone $this;
        $name = \strtolower($name);
        if (\is_array($value)) {
            foreach ($value as $v) {
                $c->responseHeaders[$name][] = $v;
            }
        } else {
            $c->responseHeaders[$name][] = (string) $value;
        }

        return $c;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $this->waitForHeaders();

        $c = clone $this;
        unset($c->responseHeaders[\strtolower($name)]);

        return $c;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $this->waitForHeaders();

        $c       = clone $this;
        $c->body = $body;

        return $c;
    }

    public function getProtocolVersion(): string
    {
        $this->waitForHeaders();

        return $this->protocolVersion;
    }

    /**
     * Return the HTTP status code, waiting for the headers to arrive.
     *
     * A 404 or 500 is returned as a number and throws nothing.
     *
     * ```php
     * try {
     *     $status = $client->get('https://example.com/')->getStatusCode();
     * } catch (\Psr\Http\Client\NetworkExceptionInterface $e) {
     *     // connection refused, DNS failure, timeout: $e->getCode() is cURL's error number
     * }
     * ```
     *
     * @throws NetworkException when no response headers arrived because of a network failure
     * @throws RequestException when the request is invalid
     * @throws ClientException  for any other failure, such as too many redirects
     *
     * @see CurlResponse::getReasonPhrase
     */
    public function getStatusCode(): int
    {
        $this->waitForHeaders();

        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        $this->waitForHeaders();

        return $this->reasonPhrase;
    }

    public function hasHeader(string $name): bool
    {
        $this->waitForheaders();

        return isset($this->responseHeaders[\strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $this->waitForHeaders();
        $name = \strtolower($name);

        return isset($this->responseHeaders[$name]) ? $this->responseHeaders[$name] : [];
    }

    /**
     * Return the values of a header joined by ", ", or an empty string; the name is case-insensitive.
     *
     * Waits for the headers. With redirects followed, these are the headers of the last response.
     *
     * ```php
     * echo $client->get('https://example.com/')->getHeaderLine('content-type');
     * ```
     *
     * @see CurlResponse::getHeader
     */
    public function getHeaderLine(string $name): string
    {
        $this->waitForHeaders();

        return \implode(', ', $this->getHeader($name));
    }

    public function getHeaders(): array
    {
        $this->waitForHeaders();

        return $this->responseHeaders;
    }

    /**
     * Return the body stream, without waiting for the transfer.
     *
     * Reading from the stream waits for data. The stream is read once and cannot be rewound.
     * When the transfer failed, the read that reaches the failure throws a {@see NetworkException}
     * (or the {@see ClientException} that matches the failure) instead of returning an empty string.
     *
     * ```php
     * $json = \json_decode((string) $client->get('https://example.com/data.json')->getBody(), true);
     * ```
     *
     * @throws ClientException from a read, when the transfer failed
     *
     * @see CurlResponse::getStatusCode
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Return the number of bytes cURL expects to download, or null before cURL has reported it.
     *
     * Unknown sizes are reported by cURL as 0.
     *
     * @see CurlResponse::getDownloadedBytes
     */
    public function getDownloadSize(): ?int
    {
        return $this->downloadSize;
    }

    /**
     * Return the number of bytes downloaded so far, or null before cURL has reported any progress.
     *
     * ```php
     * $response = $client->get('https://example.com/big.iso');
     * $body     = $response->getBody();
     * while (!$body->eof()) {
     *     $body->read(65536);
     *     echo $response->getDownloadedBytes(), " of ", $response->getDownloadSize(), "\n";
     * }
     * ```
     *
     * @see CurlResponse::getDownloadSize
     */
    public function getDownloadedBytes(): ?int
    {
        return $this->downloaded;
    }

    /**
     * Return the number of bytes cURL expects to upload, or null before cURL has reported it.
     *
     * @see CurlResponse::getUploaded
     */
    public function getUploadSize(): ?int
    {
        return $this->uploadSize;
    }

    /**
     * Return the number of bytes uploaded so far, or null before cURL has reported any progress.
     *
     * @see CurlResponse::getUploadSize
     */
    public function getUploaded(): ?int
    {
        return $this->uploaded;
    }

    private function failure(): ClientException
    {
        $class = match ($this->errorNumber) {
            \CURLE_UNSUPPORTED_PROTOCOL, \CURLE_URL_MALFORMAT          => RequestException::class,
            \CURLE_TOO_MANY_REDIRECTS, \CURLE_BAD_CONTENT_ENCODING     => ClientException::class,
            default                                                    => NetworkException::class,
        };

        return new $class($this->request, $this->errorMessage, $this->errorNumber);
    }

    private function curlHeaderFunction($curl, $header)
    {
        $trimmed = \trim($header);
        if ('' === $trimmed) {
            // The end of a header block. The headers are complete unless this was an
            // informational (1xx) block or a redirect that cURL is about to follow.
            if (
                $this->statusCode >= 200
                && !(true === $this->options->followLocation && \in_array($this->statusCode, [301, 302, 303, 307, 308], true) && isset($this->responseHeaders['location']))
            ) {
                $this->haveHeaders = true;
                \phasync::raiseFlag($this);
            }

            return \strlen($header);
        }

        if (\str_starts_with($trimmed, 'HTTP/')) {
            // A new status line. cURL invokes this callback once per redirect
            // hop when following redirects, so every hop's headers are reset
            // here to keep only the final hop's status and headers.
            [$protocol, $code, $phrase] = \explode(' ', $trimmed, 3) + [null, null, null];
            $this->protocolVersion      = \substr($protocol, \strpos($protocol, '/') + 1);
            $this->statusCode           = \intval($code);
            $this->reasonPhrase         = $phrase ?: '';
            $this->responseHeaders      = [];

            return \strlen($header);
        }

        // Parse headers
        $parts = \explode(':', $trimmed, 2);
        if (2 === \count($parts)) {
            list($key, $value)             = $parts;
            $key                           = \strtolower(\trim($key));
            $value                         = \trim($value);
            $this->responseHeaders[$key][] = $value;
        }

        return \strlen($header);
    }

    private function curlWriteFunction(\CurlHandle $curl, string $chunk): int
    {
        $this->buffer .= $chunk;
        \phasync::raiseFlag($this);

        return \strlen($chunk);
    }

    private function curlXferInfoFunction(\CurlHandle $curl, $downloadSize, $downloaded, $uploadSize, $uploaded)
    {
        // This function don't appear to be invoked.
        $this->downloadSize = $downloadSize;
        $this->downloaded   = $downloaded;
        $this->uploadSize   = $uploadSize;
        $this->uploaded     = $uploaded;
        // Notify the event loop that something happened with $this->stream
        \phasync::raiseFlag($this);
    }

    private function waitForHeaders(): void
    {
        self::runQueue();
        while (!$this->haveHeaders && !$this->done) {
            $this->awaitChange();
        }
        if (!$this->haveHeaders) {
            throw $this->failure();
        }
    }

    /**
     * Suspend until the transfer has something new. When the wait is interrupted, for example by
     * a cancellation, the transfer is cancelled with it.
     */
    private function awaitChange(): void
    {
        try {
            \phasync::awaitFlag($this);
        } catch (\Throwable $e) {
            if (!$this->done) {
                \phasync::cancel($this->transfer);
            }
            throw $e;
        }
    }

    private function applyOptions(HttpClientOptions $options): void
    {
        foreach ([
            'userAgent'             => \CURLOPT_USERAGENT,
            'autoReferer'           => \CURLOPT_AUTOREFERER,
            'crlf'                  => \CURLOPT_CRLF,
            'disallowUsernameInUrl' => \CURLOPT_DISALLOW_USERNAME_IN_URL,
            'dnsShuffleAddresses'   => \CURLOPT_DNS_SHUFFLE_ADDRESSES,
            'haProxyProtocol'       => \CURLOPT_HAPROXYPROTOCOL,
            'followLocation'        => \CURLOPT_FOLLOWLOCATION,
            'forbidReuse'           => \CURLOPT_FORBID_REUSE,
            'freshConnect'          => \CURLOPT_FRESH_CONNECT,
            'tcpNoDelay'            => \CURLOPT_TCP_NODELAY,
            'httpProxyTunnel'       => \CURLOPT_HTTPPROXYTUNNEL,
            'httpContentDecoding'   => \CURLOPT_HTTP_CONTENT_DECODING,
            'sslVerifyPeer'         => \CURLOPT_SSL_VERIFYPEER,
            'timeoutMs'             => \CURLOPT_TIMEOUT_MS,
            'connectTimeoutMs'      => \CURLOPT_CONNECTTIMEOUT_MS,
            'maxRedirs'             => \CURLOPT_MAXREDIRS,
            'cookie'                => \CURLOPT_COOKIE,
            'cookieFile'            => \CURLOPT_COOKIEFILE,
            'cookieJar'             => \CURLOPT_COOKIEJAR,
            'encoding'              => \CURLOPT_ENCODING,
            'postFields'            => \CURLOPT_POSTFIELDS,
            'referer'               => \CURLOPT_REFERER,
            'range'                 => \CURLOPT_RANGE,
            'username'              => \CURLOPT_USERNAME,
            'password'              => \CURLOPT_PASSWORD,
            'headers'               => \CURLOPT_HTTPHEADER,
            'resolve'               => \CURLOPT_RESOLVE,
            'pathAsIs'              => \CURLOPT_PATH_AS_IS,
            'sslEnableAlpn'         => \CURLOPT_SSL_ENABLE_ALPN,
            'sslEnableNpn'          => \CURLOPT_SSL_ENABLE_NPN,
            'proxySslVerifyPeer'    => \CURLOPT_PROXY_SSL_VERIFYPEER,
            'proxySslVerifyHost'    => \CURLOPT_PROXY_SSL_VERIFYHOST,
            'proxy'                 => \CURLOPT_PROXY,
            'proxyAuth'             => \CURLOPT_PROXYAUTH,
            'proxyPort'             => \CURLOPT_PROXYPORT,
            'proxyType'             => \CURLOPT_PROXYTYPE,
            'resumeFrom'            => \CURLOPT_RESUME_FROM,
            'ipResolve'             => \CURLOPT_IPRESOLVE,
        ] as $prop => $opt) {
            if (null !== $options->$prop) {
                \curl_setopt($this->curl, $opt, $options->$prop);
            }
        }
    }

    /**
     * Start every queued transfer. Inside a coroutine they run in the background of its context;
     * outside one they run to the end here, as that is the only way to drive the event loop.
     */
    private static function runQueue(): void
    {
        $start = static function () {
            while (self::$fetchQueue->tryDequeue($next)) {
                $next->transfer = \phasync::go($next->goCurl(...));
            }
        };
        if (!self::$fetchQueue->isEmpty()) {
            \phasync::isRunning() ? $start() : \phasync::run($start);
        }
    }
}
