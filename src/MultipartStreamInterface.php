<?php

namespace phasync\HttpClient;

use Psr\Http\Message\StreamInterface;

/**
 * A request body stream representing a `multipart/form-data` payload.
 *
 * {@see HttpClient::sendRequest()} recognizes any body implementing this
 * interface and sends {@see self::getContentType()} as the request's
 * "Content-Type" header (including the boundary), instead of requiring the
 * caller to set it manually.
 */
interface MultipartStreamInterface extends StreamInterface
{
    /**
     * The full value for the "Content-Type" header, including the boundary
     * parameter (e.g. `multipart/form-data; boundary=...`).
     */
    public function getContentType(): string;
}
