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
 * A PSR-18 compliant HTTP client which supports asynchronous
 * fetching.
 */
final class HttpClient implements ClientInterface
{
    private HttpClientOptions $options;

    /**
     * The head of the middleware chain. Initially a terminal handler that
     * performs the real request; each {@see self::addMiddlewareFunction()}
     * call wraps it in a new handler.
     */
    private ClientInterface $handler;

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
     * Wrap the sendRequest method. Example:
     *
     * $client->addMiddlewareFunction(
     *     function(RequestInterface $request, ClientInterface $next): ResponseInterface {
     *         // Do stuff with request here
     *         $response = $next->sendRequest($request);
     *         // Do stuff with response here
     *         return $response;
     *     }
     * );
     *
     * Each middleware wraps every one added before it, so the last one
     * added is the outermost: it runs first, and its `$next` calls into
     * the previous one, and so on down to the client's own request
     * handling.
     *
     * @param \Closure(RequestInterface,ClientInterface):ResponseInterface $middleware
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
     * Perform an HTTP request.
     *
     * @param string|UriInterface $url    The URL to fetch from
     * @param string              $method The method
     *
     * @throws \InvalidArgumentException if an unknown option is passed in `$options`
     *
     * @return CurlResponse
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

    public function get(string|UriInterface $url, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('GET', $url, null, $options);
    }

    public function post(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('POST', $url, $requestData, $options);
    }

    public function put(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options = null): ResponseInterface
    {
        return $this->request('PUT', $url, $requestData, $options);
    }
}
