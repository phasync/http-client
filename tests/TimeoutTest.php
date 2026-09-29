<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;

test('timeoutMs aborts a slow request early with a RuntimeException', function () {
    $t      = \microtime(true);
    $client = new HttpClient(['timeoutMs' => 200]);

    expect(function () use ($client) {
        $client->get(TestServer::baseUrl() . '/delay/3')->getStatusCode();
    })->toThrow(RuntimeException::class);

    // It must have actually aborted early, not waited for the 3s delay.
    expect(\microtime(true) - $t)->toBeLessThan(2.0);
});

test('a per-request timeoutMs overrides the client default', function () {
    $client = new HttpClient(['timeoutMs' => 50]);

    // The default would abort this in 50ms; the override gives it enough
    // time to succeed.
    $response = $client->get(TestServer::baseUrl() . '/delay/0.2', ['timeoutMs' => 2000]);

    expect($response->getStatusCode())->toBe(200);
});

test('the client is still usable after a timeout', function () {
    $client = new HttpClient(['timeoutMs' => 200]);

    try {
        $client->get(TestServer::baseUrl() . '/delay/3')->getStatusCode();
    } catch (RuntimeException) {
    }

    $response = $client->get(TestServer::baseUrl() . '/get');
    expect($response->getStatusCode())->toBe(200);
});
