<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;

test('redirects are followed by default, ending on the final response', function () {
    $client = new HttpClient();
    $target = TestServer::baseUrl() . '/get';

    $response = $client->get(TestServer::baseUrl() . '/redirect-to?url=' . \urlencode($target));

    expect($response->getStatusCode())->toBe(200);
    $body = \json_decode((string) $response->getBody(), true);
    expect($body['method'])->toBe('GET');
});

test('followLocation=false stops at the redirect response', function () {
    $client = new HttpClient();
    $target = TestServer::baseUrl() . '/get';

    $response = $client->get(
        TestServer::baseUrl() . '/redirect-to?url=' . \urlencode($target),
        ['followLocation' => false]
    );

    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('location'))->toBe($target);
});

test('maxRedirs stops following after the given number of hops', function () {
    $client = new HttpClient();

    expect(function () use ($client) {
        $client->get(TestServer::baseUrl() . '/redirect/5', ['maxRedirs' => 2])->getStatusCode();
    })->toThrow(RuntimeException::class);
});

test('a redirect chain within maxRedirs reaches the final response', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/redirect/3', ['maxRedirs' => 10]);

    expect($response->getStatusCode())->toBe(200);
    expect(\json_decode((string) $response->getBody(), true))->toBe(['done' => true]);
});
