<?php

namespace phasync\HttpClient;

use phasync\Psr\Request;
use phasync\Psr\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-18 HTTP client that runs concurrent requests on `curl_multi`.
 *
 * `get()`, `post()`, `put()`, `request()` and `sendRequest()` return a {@see CurlResponse} at once.
 * The transfer starts when its status, headers or body is first read, and every request created
 * up to then starts together. Inside a coroutine only the reading coroutine is suspended; outside
 * one, the reading call blocks until the response has arrived. An HTTP error status such as 404 or
 * 500 is a normal response and throws nothing.
 *
 * ```php
 * use phasync\HttpClient\HttpClient;
 *
 * $client = new HttpClient(['timeoutMs' => 5000]);
 *
 * // Neither transfer has started yet, so both run at the same time once the first is read.
 * $a = $client->get('https://example.com/a');
 * $b = $client->get('https://example.com/b');
 *
 * echo $a->getStatusCode(), "\n";
 * echo $b->getBody();
 * ```
 *
 * Cancelling a coroutine that is waiting for a response throws `phasync\CancelledException` in it.
 * The client stays usable.
 *
 * @see HttpClient::request
 * @see HttpClientOptions
 * @see CurlResponse
 * @see MultipartStream
 * @see phasync::go
 */
final class HttpClient implements ClientInterface
{
    private HttpClientOptions $options;

    /**
     * The head of the middleware chain. Initially a terminal handler that
     * performs the real request; each {@see HttpClient::addMiddlewareFunction()}
     * call wraps it in a new handler.
     */
    private ClientInterface $handler;

    /**
     * Create a client whose options are the defaults for every request it sends.
     *
     * ```php
     * $client = new HttpClient([
     *     'userAgent'  => 'my-app/1.0',
     *     'timeoutMs'  => 5000,
     *     'maxRedirs'  => 5,
     * ]);
     * ```
     *
     * @param array<string,mixed>|HttpClientOptions $defaultRequestOptions Option values keyed by {@see HttpClientOptions} property name
     *
     * @throws \InvalidArgumentException for an option name that does not exist
     *
     * @see HttpClientOptions
     */
    public function __construct(array|HttpClientOptions $defaultRequestOptions = [])
    {
        $this->options = HttpClientOptions::create($defaultRequestOptions);
        $this->handler = new class($this) implements ClientInterface {
            public function __construct(private HttpClient $client)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->client->doSendRequest($request);
            }
        };
    }

    /**
     * Wrap {@see HttpClient::sendRequest()} in a function that can change the request or the response.
     *
     * Every request goes through the middleware, including those from `get()`, `post()`, `put()`
     * and `request()`. Each middleware wraps every one added before it, so the last one added is
     * the outermost: it runs first, and its `$next` calls the previous one, down to the client's
     * own request handling. A middleware that does not call `$next` answers the request itself.
     *
     * Calling `$next->sendRequest()` returns the response before its transfer has started, like
     * `sendRequest()` does.
     *
     * ```php
     * $client->addMiddlewareFunction(
     *     function (RequestInterface $request, ClientInterface $next): ResponseInterface {
     *         return $next->sendRequest($request->withHeader('X-Request-Id', \bin2hex(\random_bytes(8))));
     *     }
     * );
     * ```
     *
     * @param \Closure(RequestInterface,ClientInterface):ResponseInterface $middleware
     *
     * @see HttpClient::sendRequest
     */
    public function addMiddlewareFunction(\Closure $middleware): void
    {
        $next          = $this->handler;
        $this->handler = new class($next, $middleware) implements ClientInterface {
            public function __construct(private ClientInterface $next, private \Closure $middleware)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->middleware)($request, $this->next);
            }
        };
    }

    /**
     * Send a PSR-7 request with the client's default options.
     *
     * The request's `Cookie` and `User-Agent` headers replace the `cookie` and `userAgent` options,
     * and the other headers are sent as they are. A body that implements {@see MultipartStreamInterface}
     * also sets the `Content-Type` header. The response is returned before its transfer has started.
     *
     * A failed transfer (connection refused, DNS failure, timeout, too many redirects) throws a
     * `\RuntimeException` from the response's `getStatusCode()`, not from here, and not a PSR-18
     * `ClientExceptionInterface`. The exception code is cURL's error number.
     *
     * ```php
     * use phasync\Psr\Request;
     *
     * $response = $client->sendRequest(Request::create('GET', 'https://example.com/'));
     * echo $response->getStatusCode();
     * ```
     *
     * @see HttpClient::request
     * @see HttpClient::addMiddlewareFunction
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->handler->sendRequest($request);
    }

    /**
     * Performs the actual HTTP request via cURL. Used as the innermost
     * handler of the middleware chain; not part of the public API.
     *
     * @internal
     */
    public function doSendRequest(RequestInterface $request): ResponseInterface
    {
        $method  = $request->getMethod();
        $uri     = $request->getUri();
        $options = [
            'headers'             => [],
        ];
        foreach ($request->getHeaders() as $name => $values) {
            $lName = \strtolower($name);
            if ('cookie' === $lName) {
                $options['cookie'] = \implode('; ', $values);
            } elseif ('user-agent' === $lName) {
                $options['userAgent'] = $values[0];
            } else {
                foreach ($values as $value) {
                    $options['headers'][] = \sprintf('%s: %s', $name, $value);
                }
            }
        }

        $body = $request->getBody();

        if ($body instanceof MultipartStreamInterface) {
            $options['headers'][] = 'Content-Type: ' . $body->getContentType();
        }

        return new CurlResponse($method, $uri, $body, $this->options->overrideFrom($options));
    }

    /**
     * Send a request with any method, a body and options for this request only.
     *
     * `$options` are applied on top of the client's defaults for this request. A `null` option
     * means "not set" and keeps the default, and an array such as `headers` replaces the default
     * array instead of merging with it. Entries in `headers` are `Name: value` strings.
     *
     * `$requestData` as a string or a PSR-7 stream is sent as the body. An array or object is
     * encoded by the `Content-Type` header: `application/x-www-form-urlencoded` when there is none,
     * or `application/json` when the header says so. For GET the data goes in the query string.
     *
     * Returns a {@see CurlResponse} unless a middleware answers with another response. The
     * transfer starts when the response is first read.
     *
     * ```php
     * $response = $client->request('DELETE', 'https://example.com/items/7', null, [
     *     'headers' => ['Authorization: Bearer ' . $token],
     * ]);
     * echo $response->getStatusCode();
     *
     * $response = $client->request('PATCH', 'https://example.com/items/7', ['done' => true], [
     *     'headers' => ['Content-Type: application/json'],
     * ]);
     * ```
     *
     * @param string                                     $method      The HTTP method
     * @param string|UriInterface                        $url         The URL to fetch
     * @param mixed                                      $requestData The body: a string, a stream, an array or an object
     * @param array<string,mixed>|HttpClientOptions|null $options     Options for this request only
     *
     * @throws \InvalidArgumentException for an option name that does not exist
     * @throws \RuntimeException         for a `headers` entry without a colon, and for array or object data with a `Content-Type` other than the two above
     *
     * @return ResponseInterface the response; a {@see CurlResponse} unless a middleware returns another
     *
     * @see HttpClient::get
     * @see HttpClient::post
     * @see HttpClient::sendRequest
     */
    public function request(string $method, string|UriInterface $url, mixed $requestData = null, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        $options = $this->options->overrideFrom($options);
        $headers = [];
        if (null !== $options->headers) {
            foreach ($options->headers as $header) {
                $parts = \explode(':', $header, 2);
                if (isset($parts[1])) {
                    $headers[\strtolower($parts[0])][] = \trim($parts[1]);
                } else {
                    throw new \RuntimeException("Invalid header '$header'");
                }
            }
        }
        if (empty($headers['user-agent']) && null !== $options->userAgent) {
            $headers['user-agent'] = $options->userAgent;
        }
        if (empty($headers['cookie']) && null !== $options->cookie) {
            $headers['cookie'] = [$options->cookie];
        }

        if (!$requestData instanceof StreamInterface && (\is_array($requestData) || \is_object($requestData))) {
            if (!empty($headers['content-type'])) {
                switch ($headers['content-type'][0]) {
                    case 'application/x-www-form-urlencoded':
                        $requestData = \http_build_query($requestData);
                        break;
                    case 'application/json':
                        $requestData = \json_encode($requestData);
                        break;
                    default:
                        throw new \RuntimeException("Unsupported content-type '" . $headers['content-type'][0] . "' for post data provided as array or object");
                }
            } else {
                $headers['content-type'] = ['application/x-www-form-urlencoded'];
                $requestData             = \http_build_query($requestData);
            }
        }

        $request = Request::create($method, $url);
        foreach ($headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }
        $request = $request->withBody(StreamFactory::create($requestData));

        $oldOptions    = $this->options;
        $this->options = $options;
        try {
            return $this->sendRequest($request);
        } finally {
            $this->options = $oldOptions;
        }
    }

    /**
     * Send a GET request.
     *
     * To send data in the query string, put it in the URL or use `request('GET', $url, $data)`.
     *
     * ```php
     * $response = $client->get('https://example.com/search?q=phasync', ['timeoutMs' => 2000]);
     * $data     = \json_decode((string) $response->getBody(), true);
     * ```
     *
     * @param string|UriInterface                        $url     The URL to fetch
     * @param array<string,mixed>|HttpClientOptions|null $options Options for this request only
     *
     * @throws \InvalidArgumentException for an option name that does not exist
     * @throws \RuntimeException         for a `headers` entry without a colon
     *
     * @see HttpClient::post
     * @see HttpClient::request
     */
    public function get(string|UriInterface $url, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('GET', $url, null, $options);
    }

    /**
     * Send a POST request with a body.
     *
     * An array or object is sent form-encoded; pass `['headers' => ['Content-Type: application/json']]`
     * to send it as JSON. A {@see MultipartStream} is sent as `multipart/form-data`.
     *
     * ```php
     * $response = $client->post('https://example.com/login', ['user' => 'frode', 'password' => $secret]);
     *
     * $response = $client->post('https://example.com/api', ['name' => 'x'], [
     *     'headers' => ['Content-Type: application/json'],
     * ]);
     * ```
     *
     * @param string|UriInterface                        $url         The URL to post to
     * @param mixed                                      $requestData The body: a string, a stream, an array or an object
     * @param array<string,mixed>|HttpClientOptions|null $options     Options for this request only
     *
     * @throws \InvalidArgumentException for an option name that does not exist
     * @throws \RuntimeException         for a `headers` entry without a colon, and for array or object data with a `Content-Type` other than `application/x-www-form-urlencoded` or `application/json`
     *
     * @see HttpClient::put
     * @see HttpClient::request
     * @see MultipartStream
     */
    public function post(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('POST', $url, $requestData, $options);
    }

    /**
     * Send a PUT request with a body.
     *
     * Takes the body and the options like {@see HttpClient::post()}.
     *
     * ```php
     * $response = $client->put('https://example.com/items/7', \json_encode(['name' => 'x']), [
     *     'headers' => ['Content-Type: application/json'],
     * ]);
     * ```
     *
     * @param string|UriInterface                        $url         The URL to put to
     * @param mixed                                      $requestData The body: a string, a stream, an array or an object
     * @param array<string,mixed>|HttpClientOptions|null $options     Options for this request only
     *
     * @throws \InvalidArgumentException for an option name that does not exist
     * @throws \RuntimeException         for a `headers` entry without a colon, and for array or object data with a `Content-Type` other than `application/x-www-form-urlencoded` or `application/json`
     *
     * @see HttpClient::post
     * @see HttpClient::request
     */
    public function put(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('PUT', $url, $requestData, $options);
    }
}
