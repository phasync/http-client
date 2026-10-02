<?php

namespace phasync\HttpClient;

use Psr\Http\Client\NetworkExceptionInterface;

/**
 * No response arrived, or the response stopped arriving, because of a network failure.
 *
 * Thrown for a refused or reset connection, a DNS failure, a TLS failure and a timeout. When the
 * failure comes after the response headers, it is thrown from reading the body instead of from
 * `sendRequest()`. The code is cURL's error number, such as `CURLE_OPERATION_TIMEDOUT`.
 *
 * ```php
 * try {
 *     $response = $client->sendRequest($request);
 * } catch (\Psr\Http\Client\NetworkExceptionInterface $e) {
 *     // retry later; $e->getRequest() is the request that failed
 * }
 * ```
 *
 * @see RequestException
 * @see ClientException
 * @see HttpClient::sendRequest
 */
class NetworkException extends ClientException implements NetworkExceptionInterface
{
}
