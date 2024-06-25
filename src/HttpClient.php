<?php

namespace phasync\HttpClient;

use Charm\Options\IllegalOperationException;
use Charm\Options\UnknownOptionException;
use phasync\Psr\MultipartStreamInterface;
use phasync\Psr\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-18 compliant HTTP client which supports asynchronous
 * fetching.
 */
final class HttpClient implements ClientInterface
{
    private HttpClientOptions $options;

    /**
     * Enables middleware
     */
    private ?ClientInterface $client = null;

    public function __construct(array|HttpClientOptions $defaultRequestOptions = [])
    {
        $this->options = HttpClientOptions::create($defaultRequestOptions);
    }

    /**
     * Wrap the sendRequest method. Example:
     *
     * $client->addMiddlewareFunction(
     *     function(RequestInterface $request, ClientInterface $client): ResponseInterface {
     *         // Do stuff with request here
     *         $response = $client->sendRequest($request);
     *         // Do stuff with response here
     *         return $response;
     *     }
     * );
     *
     * @param \Closure<RequestInterface,ClientInterface,ResponseInterface> $middleware
     *
     * @return void
     */
    public function addMiddlewareFunction(\Closure $middleware)
    {
        $client       = $this->client ?? $this;
        $this->client = new class($client, $middleware) extends ClientInterface {
            public function __construct(private ClientInterface $client, private \Closure $middleware)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->middleware)($request, $this->client);
            }
        };
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (null !== $this->client) {
            return ($this->client)($request);
        }
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
            $options['headers']['content-type'] = [$body->getContentType()];
        }

        return new CurlResponse($method, $uri, $body, $this->options->overrideFrom($options));
    }

    /**
     * Perform an HTTP request.
     *
     * @param string|UriInterface $url    The URL to fetch from
     * @param string              $method The method
     *
     * @throws UnknownOptionException
     * @throws IllegalOperationException
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

        if (\is_array($requestData) || \is_object($requestData)) {
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

        $request = new Request($method, $url, $headers, $requestData);

        $oldOptions    = $this->options;
        $this->options = $this->options->overrideFrom($options);
        $result        = $this->sendRequest($request);
        $this->options = $oldOptions;

        return $result;
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
        return $this->request('PUT', $url, $requestData, $this->options->overrideFrom($options));
    }
}
