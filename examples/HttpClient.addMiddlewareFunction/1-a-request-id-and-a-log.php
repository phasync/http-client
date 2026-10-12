<?php

use phasync\HttpClient\HttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

$client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next): ResponseInterface {
    return $next->sendRequest($request->withHeader('X-Request-Id', 'req-1'));
});
// Added last, so it runs first: it sees the request before the header is added
$client->addMiddlewareFunction(function (RequestInterface $request, ClientInterface $next): ResponseInterface {
    $response = $next->sendRequest($request);
    echo 'log: ', $request->getMethod(), ' ', $request->getUri()->getPath(), ' -> ', $response->getStatusCode(), "\n";

    return $response;
});

$echo = json_decode((string) $client->get("$base/echo")->getBody(), true);
echo 'the server got X-Request-Id: ', $echo['headers']['x-request-id'], "\n";
