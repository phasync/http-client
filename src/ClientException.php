<?php

namespace phasync\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A request failed for a reason that is neither an invalid request nor a network failure.
 *
 * It is a PSR-18 `ClientExceptionInterface` and a `\RuntimeException`. The code is cURL's error
 * number. This class itself is thrown for too many redirects and for a response body that cannot be
 * decoded; {@see NetworkException} and {@see RequestException} are the two more specific kinds.
 *
 * ```php
 * try {
 *     $client->sendRequest($request);
 * } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
 *     echo $e->getMessage(), ' (cURL error ', $e->getCode(), ')';
 * }
 * ```
 *
 * @see NetworkException
 * @see RequestException
 * @see HttpClient::sendRequest
 */
class ClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message, int $code)
    {
        parent::__construct($message, $code);
    }

    /**
     * Return the request that failed.
     *
     * @see HttpClient::sendRequest
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
