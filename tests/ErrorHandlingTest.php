<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;

test('connection refused throws a RuntimeException', function () {
    $client = new HttpClient();
    $port   = TestServer::unusedPort();

    expect(function () use ($client, $port) {
        $client->get("http://127.0.0.1:{$port}/get")->getStatusCode();
    })->toThrow(RuntimeException::class);
});

test('a DNS resolution failure throws a RuntimeException', function () {
    $client = new HttpClient();

    expect(function () use ($client) {
        $client->get('http://this-host-does-not-exist.invalid/get')->getStatusCode();
    })->toThrow(RuntimeException::class);
});

test('default options survive an exception from a failed request', function () {
    $client = new HttpClient(['userAgent' => 'default-agent']);
    $port   = TestServer::unusedPort();

    try {
        $client->get("http://127.0.0.1:{$port}/get", ['userAgent' => 'temporary-agent'])->getStatusCode();
    } catch (RuntimeException) {
    }

    $response = $client->get(TestServer::baseUrl() . '/headers');
    $body     = \json_decode((string) $response->getBody(), true);
    expect($body['headers']['user-agent'])->toBe('default-agent');
});

test('the client remains usable after a connection error', function () {
    $client = new HttpClient();
    $port   = TestServer::unusedPort();

    try {
        $client->get("http://127.0.0.1:{$port}/get")->getStatusCode();
    } catch (RuntimeException) {
    }

    $response = $client->get(TestServer::baseUrl() . '/get');
    expect($response->getStatusCode())->toBe(200);
});
