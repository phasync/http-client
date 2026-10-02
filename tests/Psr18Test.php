<?php

use phasync\HttpClient\ClientException;
use phasync\HttpClient\HttpClient;
use phasync\HttpClient\HttpClientOptions;
use phasync\HttpClient\NetworkException;
use phasync\HttpClient\RequestException;
use phasync\HttpClient\Tests\Support\TestServer;
use phasync\Psr\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * Runs sendRequest() and returns the exception it threw, failing the test when it threw nothing.
 */
function sendRequestFailure(HttpClient $client, Psr\Http\Message\RequestInterface $request): Throwable
{
    try {
        $client->sendRequest($request);
    } catch (Throwable $e) {
        return $e;
    }
    throw new RuntimeException('sendRequest() returned a response, it should have thrown');
}

test('the client is a PSR-18 ClientInterface', function () {
    expect(new HttpClient())->toBeInstanceOf(ClientInterface::class);
});

test('a refused connection throws a NetworkExceptionInterface from sendRequest', function () {
    $client  = new HttpClient();
    $request = Request::create('GET', 'http://127.0.0.1:' . TestServer::unusedPort() . '/get');

    $e = sendRequestFailure($client, $request);

    expect($e)->toBeInstanceOf(NetworkExceptionInterface::class);
    expect($e)->toBeInstanceOf(NetworkException::class);
    expect($e)->toBeInstanceOf(RuntimeException::class);
    expect((string) $e->getRequest()->getUri())->toBe((string) $request->getUri());
    expect($e->getCode())->toBe(\CURLE_COULDNT_CONNECT);
});

test('a DNS failure throws a NetworkExceptionInterface from sendRequest', function () {
    $client = new HttpClient();

    $e = sendRequestFailure($client, Request::create('GET', 'http://this-host-does-not-exist.invalid/get'));

    expect($e)->toBeInstanceOf(NetworkExceptionInterface::class);
});

test('a timeout throws a NetworkExceptionInterface from sendRequest', function () {
    $client = new HttpClient(['timeoutMs' => 200]);
    $t      = \microtime(true);

    $e = sendRequestFailure($client, Request::create('GET', TestServer::baseUrl() . '/delay/3'));

    expect($e)->toBeInstanceOf(NetworkExceptionInterface::class);
    expect(\microtime(true) - $t)->toBeLessThan(2.0);
});

test('an invalid request throws a RequestExceptionInterface from sendRequest', function (string $url) {
    $client  = new HttpClient();
    $request = Request::create('GET', $url);

    $e = sendRequestFailure($client, $request);

    expect($e)->toBeInstanceOf(RequestExceptionInterface::class);
    expect($e)->toBeInstanceOf(RequestException::class);
    expect($e)->not->toBeInstanceOf(NetworkExceptionInterface::class);
    expect((string) $e->getRequest()->getUri())->toBe($url);
})->with([
    'no host'            => ['/just/a/path'],
    'unsupported scheme' => ['foo://example.com/'],
]);

test('too many redirects throw a ClientExceptionInterface that is neither a network nor a request error', function () {
    $client = new HttpClient(['maxRedirs' => 2]);

    $e = sendRequestFailure($client, Request::create('GET', TestServer::baseUrl() . '/redirect/5'));

    expect($e)->toBeInstanceOf(ClientExceptionInterface::class);
    expect($e)->toBeInstanceOf(ClientException::class);
    expect($e)->not->toBeInstanceOf(NetworkExceptionInterface::class);
    expect($e)->not->toBeInstanceOf(RequestExceptionInterface::class);
});

test('a 4xx and a 5xx response are returned, not thrown', function (int $status, string $reason) {
    $client = new HttpClient();

    $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/status/' . $status));

    expect($response->getStatusCode())->toBe($status);
    expect($response->getReasonPhrase())->toBe($reason);
})->with([
    [404, 'Not Found'],
    [500, 'Internal Server Error'],
]);

test('the response of sendRequest is complete: status, reason, protocol version, headers', function () {
    $client = new HttpClient();

    $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/get'));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getReasonPhrase())->toBe('OK');
    expect($response->getProtocolVersion())->toBe('1.1');
    expect($response->getHeaderLine('content-type'))->toBe('application/json');
    expect($response->hasHeader('Content-Type'))->toBeTrue();
});

test('a redirect is followed and the final response returned from sendRequest', function () {
    $client = new HttpClient();

    $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/redirect/2'));

    expect($response->getStatusCode())->toBe(200);
    expect(\json_decode((string) $response->getBody(), true))->toBe(['done' => true]);
});

test('sendRequest supports HEAD, DELETE and OPTIONS', function (string $method) {
    $client = new HttpClient(['timeoutMs' => 3000]);

    $response = $client->sendRequest(Request::create($method, TestServer::baseUrl() . '/method'));

    expect($response->getStatusCode())->toBe(200);
    if ('HEAD' !== $method) {
        expect(\json_decode((string) $response->getBody(), true)['method'])->toBe($method);
    }
})->with(['HEAD', 'DELETE', 'OPTIONS']);

test('sendRequest does not change the request', function () {
    $client  = new HttpClient();
    $request = Request::create('POST', TestServer::baseUrl() . '/post')
        ->withHeader('X-Test', 'yes')
        ->withHeader('User-Agent', 'agent')
        ->withBody(phasync\Psr\StreamFactory::create('payload'));
    $headers = $request->getHeaders();

    $client->sendRequest($request);

    expect($request->getHeaders())->toBe($headers);
    expect($request->getMethod())->toBe('POST');
    expect((string) $request->getBody())->toBe('payload');
});

test('sendRequest suspends only the calling coroutine until the headers have arrived', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $ticks  = 0;
        $ticker = phasync::go(function () use (&$ticks) {
            while (true) {
                phasync::sleep(0.02);
                ++$ticks;
            }
        });
        $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/delay/0.5'));
        phasync::cancel($ticker);

        expect($response->getStatusCode())->toBe(200);
        expect($ticks)->toBeGreaterThan(5);
    });
});

test('cancelling a coroutine that waits in sendRequest cancels its transfer', function () {
    $client = new HttpClient();
    $t      = \microtime(true);

    phasync::run(function () use ($client) {
        $slow = phasync::go(fn () => $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/delay/3')));
        phasync::sleep(0.1);
        phasync::cancel($slow);

        expect(fn () => phasync::await($slow))->toThrow(phasync\CancelledException::class);
    });

    // run() ends when its coroutines have: the transfer must not run on for the 3 seconds.
    expect(\microtime(true) - $t)->toBeLessThan(1.0);
});

test('sendRequest returns when the headers have arrived, the body keeps streaming', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $t        = \microtime(true);
        $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/slow-body/0.6'));

        expect($response->getStatusCode())->toBe(200);
        expect(\microtime(true) - $t)->toBeLessThan(0.4);
        expect((string) $response->getBody())->toBe("first\nsecond");
        expect(\microtime(true) - $t)->toBeGreaterThan(0.5);
    });
});

test('a transfer that fails after the headers returns the response and throws from the body read', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/truncated'));

        expect($response->getStatusCode())->toBe(200);
        expect($response->getReasonPhrase())->toBe('OK');
        expect($response->getProtocolVersion())->toBe('1.1');

        $body = $response->getBody();
        expect(fn () => $body->getContents())->toThrow(NetworkException::class);
    });
});

test('the same failure outside a coroutine also throws from the body read', function () {
    $client   = new HttpClient();
    $response = $client->sendRequest(Request::create('GET', TestServer::baseUrl() . '/truncated'));

    expect($response->getStatusCode())->toBe(200);
    expect(fn () => (string) $response->getBody())->toThrow(RuntimeException::class);
});

test('get() still returns before the transfer starts, and a failure surfaces from the accessors with PSR-18 exceptions', function () {
    $client = new HttpClient();
    $port   = TestServer::unusedPort();

    $response = $client->get("http://127.0.0.1:{$port}/get");

    expect(fn () => $response->getStatusCode())->toThrow(NetworkException::class);
    expect(fn () => $response->getProtocolVersion())->toThrow(NetworkException::class);
    expect(fn () => $response->getReasonPhrase())->toThrow(NetworkException::class);
    expect(fn () => $response->getHeaders())->toThrow(NetworkException::class);
    expect(fn () => $response->getHeaderLine('content-type'))->toThrow(NetworkException::class);
    expect(fn () => (string) $response->getBody())->toThrow(NetworkException::class);
});

test('the exception of a failed get() carries the request', function () {
    $client = new HttpClient();
    $port   = TestServer::unusedPort();

    $response = $client->get("http://127.0.0.1:{$port}/get");

    try {
        $response->getStatusCode();
    } catch (NetworkExceptionInterface $e) {
        expect((string) $e->getRequest()->getUri())->toBe("http://127.0.0.1:{$port}/get");
        expect($e->getRequest()->getMethod())->toBe('GET');

        return;
    }
    throw new RuntimeException('no exception');
});

test('a middleware sees the failure of $next->sendRequest through the response, and sendRequest throws it', function () {
    $client = new HttpClient();
    $client->addMiddlewareFunction(function ($request, ClientInterface $next) {
        return $next->sendRequest($request);
    });

    $e = sendRequestFailure($client, Request::create('GET', 'http://127.0.0.1:' . TestServer::unusedPort() . '/get'));

    expect($e)->toBeInstanceOf(NetworkExceptionInterface::class);
});

test('the response exposes the options it was sent with', function () {
    $client   = new HttpClient(['userAgent' => 'agent-x']);
    $response = $client->get(TestServer::baseUrl() . '/get');

    expect($response->options)->toBeInstanceOf(HttpClientOptions::class);
    expect($response->options->userAgent)->toBe('agent-x');
});
