<?php

namespace phasync\HttpClient;

use phasync\Psr\ComposableStream;

/**
 * A `multipart/form-data` request body built from a nested array of fields.
 *
 * Scalar and {@see \Stringable} values become plain form fields. A `resource`
 * (as returned by `fopen()`) or an `\SplFileInfo` becomes a file part, using
 * its basename as the filename and `mime_content_type()` (falling back to
 * `application/octet-stream`) as its content type. Nested arrays produce
 * PHP-style bracketed field names, e.g. `tags[0]`, `tags[1]`.
 *
 * ```php
 * $body = new MultipartStream([
 *     'title'      => 'My upload',
 *     'tags'       => ['a', 'b'],
 *     'attachment' => fopen('/path/to/file.txt', 'r'),
 * ]);
 * $response = $client->post('https://example.com/upload', $body);
 * ```
 *
 * The whole body is built lazily while it is read, but note that cURL reads
 * the entire body into memory up front (via `__toString()`) before a request
 * is sent, so this does not stream large uploads with bounded memory.
 *
 * @see MultipartStreamInterface
 * @see HttpClient::post
 */
final class MultipartStream extends ComposableStream implements MultipartStreamInterface
{
    private string $boundary;

    /**
     * Create the body from the fields.
     *
     * ```php
     * $body = new MultipartStream(['name' => 'Frode', 'files' => [\fopen('a.txt', 'r'), \fopen('b.txt', 'r')]]);
     * ```
     *
     * @param array<string,mixed> $fields   Field name to value: a scalar, a Stringable, a stream resource, an SplFileInfo, or a nested array
     * @param string|null         $boundary The boundary string; random when null
     *
     * @see MultipartStream::getContentType
     */
    public function __construct(array $fields, ?string $boundary = null)
    {
        $this->boundary = $boundary ?? ('phasync' . \bin2hex(\random_bytes(16)));

        $source = (function () use ($fields) {
            yield from $this->walk($fields);
            yield "--{$this->boundary}--\r\n";
        })();

        parent::__construct(readFunction: static function () use ($source) {
            if ($source->valid()) {
                $chunk = $source->current();
                $source->next();

                return $chunk;
            }

            return null;
        });
    }

    /**
     * Return the `Content-Type` header value, `multipart/form-data` with this body's boundary.
     *
     * ```php
     * $body = new MultipartStream(['a' => '1'], 'my-boundary');
     * echo $body->getContentType(); // multipart/form-data; boundary=my-boundary
     * ```
     *
     * @see MultipartStreamInterface::getContentType
     */
    public function getContentType(): string
    {
        return "multipart/form-data; boundary={$this->boundary}";
    }

    private function walk(mixed $value, ?string $prefix = null): \Generator
    {
        if (\is_iterable($value)) {
            foreach ($value as $key => $item) {
                yield from $this->walk($item, null !== $prefix ? "{$prefix}[{$key}]" : (string) $key);
            }

            return;
        }

        if (null === $prefix) {
            throw new \InvalidArgumentException('Top-level multipart fields must be an array');
        }

        if ($value instanceof \SplFileInfo) {
            $value = $value->openFile('r');
        }

        if (\is_resource($value) && 'stream' === \get_resource_type($value)) {
            $meta        = \stream_get_meta_data($value);
            $filename    = \basename($meta['uri'] ?? 'unnamed-file');
            $contentType = (\function_exists('mime_content_type') ? @\mime_content_type($value) : false) ?: 'application/octet-stream';
            \rewind($value);
            yield $this->boundaryHeader([
                "Content-Disposition: form-data; name=\"{$prefix}\"; filename=\"{$filename}\"",
                "Content-Type: {$contentType}",
            ]);
            while (!\feof($value)) {
                $chunk = \fread($value, 65536);
                if (false === $chunk) {
                    throw new \RuntimeException("Unable to read from stream for field '{$prefix}'");
                }
                yield $chunk;
            }
            yield "\r\n";

            return;
        }

        yield $this->boundaryHeader(["Content-Disposition: form-data; name=\"{$prefix}\""]);
        yield $value . "\r\n";
    }

    /**
     * @param string[] $headers
     */
    private function boundaryHeader(array $headers): string
    {
        return "--{$this->boundary}\r\n" . \implode("\r\n", $headers) . "\r\n\r\n";
    }
}
