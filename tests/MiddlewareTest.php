<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;
use phasync\Psr\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('a middleware wraps the real request without recursing infinitely', function () {
    $client = new HttpClient();
    $trace  = [];

    $client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next) use (&$trace): ResponseInterface {
        $trace[]  = 'before';
        $response = $next->sendRequest($request);
        $trace[]  = 'after';

        return $response;
    });

    $response = $client->get(TestServer::baseUrl() . '/get');

    expect($response->getStatusCode())->toBe(200);
    expect($trace)->toBe(['before', 'after']);
});

test('middlewares run outermost-first, the last one added being outermost', function () {
    $client = new HttpClient();
    $trace  = [];

    $client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next) use (&$trace): ResponseInterface {
        $trace[]  = 'first:before';
        $response = $next->sendRequest($request);
        $trace[]  = 'first:after';

        return $response;
    });
    $client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next) use (&$trace): ResponseInterface {
        $trace[]  = 'second:before';
        $response = $next->sendRequest($request);
        $trace[]  = 'second:after';

        return $response;
    });

    $client->get(TestServer::baseUrl() . '/get');

    expect($trace)->toBe(['second:before', 'first:before', 'first:after', 'second:after']);
});

test('a middleware can short-circuit without calling $next', function () {
    $client = new HttpClient();

    $client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next): ResponseInterface {
        return new Response(204);
    });

    $response = $client->get(TestServer::baseUrl() . '/get');

    expect($response->getStatusCode())->toBe(204);
});
