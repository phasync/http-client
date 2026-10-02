# phasync/http-client

A fiber-based, PSR-18 compliant HTTP client for PHP, built on [phasync](https://github.com/phasync/phasync) 2 and its `src/Psr` PSR-7/PSR-17 implementation. Concurrent requests run in parallel via `curl_multi`, with extensive configuration through cURL options.

## Installation

```bash
composer require phasync/http-client
```

## Usage

### Basic usage

```php
use phasync\HttpClient\HttpClient;

$client = new HttpClient();
// Concurrent requests: neither one actually starts fetching until its body
// is read, so both run together under one curl_multi batch.
$response1 = $client->get('https://example.com/a');
$response2 = $client->get('https://example.com/b');
echo $response1->getBody();
echo $response2->getBody();
```

Inside a coroutine (`phasync::run()` / `phasync::go()`), several `sendRequest()` calls started in different coroutines run concurrently the same way, and `sendAsyncRequest()` gives the lazy, no-coroutines style with a PSR-7 request.

### POST request

```php
$response = $client->post('https://example.com/post', ['foo' => 'bar']);
echo $response->getBody();
```

A string, a PSR-7 `StreamInterface` (including a plain resource wrapped in `phasync\Psr\ResourceStream`), or an array/object (encoded as `application/x-www-form-urlencoded` or JSON, based on the `Content-Type` header) may be given as the body.

### Multipart uploads

```php
use phasync\HttpClient\MultipartStream;

$body = new MultipartStream([
    'title'      => 'My upload',
    'attachment' => fopen('/path/to/file.txt', 'r'),
]);
$response = $client->post('https://example.com/upload', $body);
```

`MultipartStream::getContentType()` supplies the `Content-Type` header (including the boundary) automatically. The whole body is read into memory before the request is sent (cURL's own requirement), so this does not stream large uploads with bounded memory.

### PSR-18 client usage

```php
use Psr\Http\Client\NetworkExceptionInterface;

try {
    $psr7Response = $client->sendRequest($psr7Request);
} catch (NetworkExceptionInterface $e) {
    // no response: connection refused, DNS failure, TLS failure, timeout; $e->getRequest()
}
```

`sendRequest()` waits (suspending only the calling coroutine) until the response headers have arrived, then returns a complete response, or throws a `Psr\Http\Client\ClientExceptionInterface`:

| Exception | When |
| --- | --- |
| `phasync\HttpClient\RequestException` (`RequestExceptionInterface`) | the request is invalid: a URL without a host, an unsupported scheme |
| `phasync\HttpClient\NetworkException` (`NetworkExceptionInterface`) | no response arrived: refused or reset connection, DNS or TLS failure, timeout |
| `phasync\HttpClient\ClientException` (`ClientExceptionInterface`) | any other failure, such as too many redirects |

All three extend `\RuntimeException`, and the code is cURL's error number. A 4xx or 5xx response is returned, not thrown. The body keeps streaming after `sendRequest()` returns; if the transfer fails while you read it, the read throws a `NetworkException`.

`sendAsyncRequest($request)` is the lazy counterpart of `sendRequest()`, for PSR-7 requests; `get()`, `post()`, `put()` and `request()` are lazy too. None of them is PSR-18. They return before the transfer has started so that several requests can run together, and a failure is thrown by the first read of the status, headers or body, with the same exceptions.

### Middleware

```php
$client->addMiddlewareFunction(
    function (RequestInterface $request, ClientInterface $next): ResponseInterface {
        // ... inspect/modify $request ...
        $response = $next->sendRequest($request);
        // ... inspect/modify $response ...
        return $response;
    }
);
```

Each middleware wraps every one added before it, so the last one added runs first (outermost) and its `$next` calls into the previous one.

### Redirects and timeouts

```php
$client = new HttpClient([
    'followLocation' => true,   // default
    'maxRedirs'      => 20,
    'timeoutMs'      => 5000,
]);
```

Options passed to `get()`/`post()`/`put()`/`request()` override the client's defaults for that one call only.

## Configuration options

`HttpClientOptions` mirrors cURL options as plain, mostly-nullable properties: `userAgent`, `timeoutMs`, `connectTimeoutMs`, `followLocation`, `maxRedirs`, `sslVerifyPeer`, `cookie`, `proxy`, and more. See [`src/HttpClientOptions.php`](src/HttpClientOptions.php) for the full, documented list.

## Cancellation and timeouts inside a coroutine

`phasync::cancel($fiber)` on a coroutine that is awaiting a request throws `phasync\CancelledException` from it; the client and its underlying `curl_multi` service remain usable for subsequent requests. A `timeoutMs` option aborts a request via cURL's own timeout and throws a `NetworkException`.

## Requirements

- PHP 8.2+
- `phasync/phasync` 2.0 (beta)

No other runtime dependencies: this client uses phasync's own `src/Psr` PSR-7/PSR-17 implementation exclusively.

## Development

```bash
composer install
composer test          # once with phasync/phasync
```

## License

MIT, see [LICENSE](LICENSE).
