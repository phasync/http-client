<?php

use phasync\HttpClient\HttpClient;

test('http GET request', function () {
    $c            = new HttpClient();
    $response     = $c->get('https://httpbin.org/get');
    $responseDate = $response->getHeaderLine('date');
    expect(\strtotime($responseDate))->toBeInt();
});
test('http POST request', function () {
    $c            = new HttpClient();
    $response     = $c->post('https://httpbin.org/post', ['foo' => 'bar']);
    $responseDate = $response->getHeaderLine('date');
    expect(\strtotime($responseDate))->toBeInt();

    $body = \json_decode($response->getBody(), true);
    expect($body['form'])->toBe(['foo' => 'bar']);
});
test('http handles 404 Not Found', function () {
    $c        = new HttpClient();
    $response = $c->get('https://httpbin.org/status/404');
    expect($response->getStatusCode())->toBe(404);
});
test('http request with timeout', function () {
    expect(function () {
        $c        = new HttpClient(['timeoutMs' => 1000]);
        $response = $c->get('https://httpbin.org/delay/4'); // Delays response for 5 seconds
        $response->getStatusCode();
    })->toThrow(RuntimeException::class);
});

test('concurrent GET requests', function () {
    $t = \microtime(true);
    $c = new HttpClient();
    $a = $c->get('https://httpbin.org/delay/3');
    $b = $c->get('https://httpbin.org/delay/3');
    $a->getBody() . $b->getBody();

    expect(\microtime(true) - $t)->toBeGreaterThan(3)->toBeLessThan(5);
});
