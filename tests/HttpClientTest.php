<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;
use phasync\Psr\Request;

test('http GET request', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/get');

    expect($response->getStatusCode())->toBe(200);

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['method'])->toBe('GET');
});

test('http GET request with query parameters', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/get?foo=bar');

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['query'])->toBe(['foo' => 'bar']);
});

test('http POST request with array data', function () {
    $client   = new HttpClient();
    $response = $client->post(TestServer::baseUrl() . '/post', ['foo' => 'bar']);

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['form'])->toBe(['foo' => 'bar']);
    expect($body['headers']['content-type'])->toBe('application/x-www-form-urlencoded');
});

test('http POST request with json data', function () {
    $client   = new HttpClient();
    $response = $client->post(TestServer::baseUrl() . '/post', ['foo' => 'bar'], [
        'headers' => ['Content-Type: application/json'],
    ]);

    $body = \json_decode((string) $response->getBody(), true);
    expect(\json_decode($body['raw'], true))->toBe(['foo' => 'bar']);
});

test('http PUT request sends the PUT method with a body', function () {
    $client   = new HttpClient();
    $response = $client->put(TestServer::baseUrl() . '/put', 'raw-body');

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['method'])->toBe('PUT');
    expect($body['raw'])->toBe('raw-body');
});

test('http handles 404 Not Found without throwing', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/status/404');

    expect($response->getStatusCode())->toBe(404);
});

test('http handles 500 Internal Server Error without throwing', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/status/500');

    expect($response->getStatusCode())->toBe(500);
});

test('default options apply to every request, per-request options override them', function () {
    $client = new HttpClient(['userAgent' => 'default-agent']);

    $response = $client->get(TestServer::baseUrl() . '/headers');
    $body     = \json_decode((string) $response->getBody(), true);
    expect($body['headers']['user-agent'])->toBe('default-agent');

    $response2 = $client->get(TestServer::baseUrl() . '/headers', ['userAgent' => 'override-agent']);
    $body2     = \json_decode((string) $response2->getBody(), true);
    expect($body2['headers']['user-agent'])->toBe('override-agent');

    // The default is restored for the next call, even after an override.
    $response3 = $client->get(TestServer::baseUrl() . '/headers');
    $body3     = \json_decode((string) $response3->getBody(), true);
    expect($body3['headers']['user-agent'])->toBe('default-agent');
});

test('PSR-18 sendRequest() accepts a PSR-7 request built via phasync\\Psr\\Request', function () {
    $client  = new HttpClient();
    $request = Request::create('GET', TestServer::baseUrl() . '/get')
        ->withHeader('X-Test', 'yes');

    $response = $client->sendRequest($request);

    expect($response->getStatusCode())->toBe(200);
    $body = \json_decode((string) $response->getBody(), true);
    expect($body['headers']['x-test'])->toBe('yes');
});
