<?php

namespace phasync\HttpClient;

use Psr\Http\Message\StreamInterface;

/**
 * A request body stream that carries a `multipart/form-data` payload.
 *
 * {@see HttpClient::sendRequest()} sends {@see self::getContentType()}, boundary included, as the
 * `Content-Type` header of a request whose body implements this interface, so the caller sets
 * none. Implement it for a body that is built elsewhere than {@see MultipartStream}.
 *
 * ```php
 * $body = new MultipartStream(['title' => 'Report', 'file' => \fopen('report.pdf', 'r')]);
 * $client->post('https://example.com/upload', $body); // Content-Type: $body->getContentType()
 * ```
 *
 * @see MultipartStream
 * @see HttpClient::post
 */
interface MultipartStreamInterface extends StreamInterface
{
    /**
     * Return the value of the `Content-Type` header, with the boundary parameter.
     *
     * ```php
     * echo $body->getContentType(); // multipart/form-data; boundary=phasync4f2a...
     * ```
     *
     * @see MultipartStream::__construct
     */
    public function getContentType(): string;
}
