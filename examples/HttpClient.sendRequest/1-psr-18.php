<?php

use phasync\HttpClient\HttpClient;
use phasync\Psr\Request;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

$response = $client->sendRequest(Request::create('GET', "$base/hello"));
echo $response->getStatusCode(), ' ', $response->getReasonPhrase(), "\n";
echo $response->getHeaderLine('Content-Type'), "\n";
echo $response->getBody();

// An error status is a response like any other: nothing is thrown
$response = $client->sendRequest(Request::create('GET', "$base/status/503"));
echo $response->getStatusCode(), ' ', $response->getReasonPhrase(), "\n";
