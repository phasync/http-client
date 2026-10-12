<?php

use phasync\HttpClient\HttpClient;
use phasync\Psr\Request;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

$responses = [];
foreach (['one', 'two', 'three'] as $name) {
    $responses[$name] = $client->sendAsyncRequest(Request::create('GET', "$base/slow?seconds=0.1&name=$name"));
}

// The first read starts all three transfers
foreach ($responses as $name => $response) {
    echo "$name: ", $response->getBody(), "\n";
}
