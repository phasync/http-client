# phasync/http-client

The phasync HTTP Client is a powerful, fiber-based HTTP client for PHP, leveraging the concurrency features of PHP Fibers to manage non-blocking requests efficiently. This client is compliant with PSR-18 and allows extensive configuration via cURL options to tailor request handling according to your needs.

## Features

- **Concurrent HTTP requests**: Utilizes PHP fibers to perform non-blocking HTTP requests.
- **Full PSR-18 compatibility**: Fully compliant with the PSR-18 interface for HTTP clients.
- **Extensive configuration**: Customize every aspect of HTTP requests using a wide range of cURL options.

## Installation

Use Composer to install the Phasync HTTP Client in your project:

```bash
composer require phasync/http-client
```

## Usage

### Basic Usage

Here is a simple example of making concurrent GET request:

```php
use phasync\HttpClient\HttpClient;

$client = new HttpClient();
// Concurrent requests:
$response1 = $client->get('https://httpbin.org/get');
$response2 = $client->get('https://www.reddit.com/");
// All requests are performed in parallel using `curl_multi` under the hood.
echo $response1->getBody();
echo $resposne2->getBody();
```

### POST Request

To send a POST request with data:

```php
$response = $client->post('https://httpbin.org/post', [
    'foo' => 'bar'
]);
echo $response->getBody();
```

### PSR-18 Client Usage

The client supports the PSR-18 client specification:

```php
$psr7Response = $client->sendRequest($psr7Request);
```

### Handling Redirects

Automatically handle redirects:

```php
$client = new HttpClient([
    'followLocation' => true
]);
$response = $client->get('https://httpbin.org/redirect-to?url=http%3A%2F%2Fexample.com');
```

### Custom cURL Options

Customize client behavior by setting cURL options:

```php
$client = new HttpClient([
    'timeoutMs' => 1000,
    'userAgent' => 'PhasyncClient/1.0'
]);
```

## Configuration Options

The `HttpClientOptions` class provides a way to configure a variety of options for handling requests:

- `userAgent`: Set the 'User-Agent' header.
- `timeoutMs`: Maximum number of milliseconds to allow cURL functions to execute.
- `followLocation`: Follow redirects.
- `sslVerifyPeer`: Verify the peer's SSL certificate.
- And many more detailed in the class definition.

Refer to the [`HttpClientOptions`](src/HttpClientOptions.php) class for a comprehensive list of all configurable options.

## Contributing

Contributions are welcome! Please feel free to submit pull requests or create issues for bugs and feature requests.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).
