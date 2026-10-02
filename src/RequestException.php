<?php

namespace phasync\HttpClient;

use Psr\Http\Client\RequestExceptionInterface;

/**
 * The request could not be sent because it is invalid.
 *
 * Thrown for a URL without a host and for a URL scheme that cURL does not support. The code is
 * cURL's error number.
 *
 * ```php
 * try {
 *     $client->sendRequest(\phasync\Psr\Request::create('GET', '/no/host'));
 * } catch (\Psr\Http\Client\RequestExceptionInterface $e) {
 *     echo $e->getMessage();
 * }
 * ```
 *
 * @see NetworkException
 * @see ClientException
 * @see HttpClient::sendRequest
 */
class RequestException extends ClientException implements RequestExceptionInterface
{
}
