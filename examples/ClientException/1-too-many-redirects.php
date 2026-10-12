<?php

use phasync\HttpClient\ClientException;
use phasync\HttpClient\HttpClient;
use phasync\Psr\Request;
use Psr\Http\Client\ClientExceptionInterface;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient(['maxRedirs' => 3]);

try {
    $client->sendRequest(Request::create('GET', "$base/loop"));   // redirects to itself
} catch (ClientException $e) {
    echo get_class($e), "\n";
    var_dump($e instanceof ClientExceptionInterface);
    echo 'request: ', $e->getRequest()->getMethod(), ' ', $e->getRequest()->getUri()->getPath(), "\n";
}
