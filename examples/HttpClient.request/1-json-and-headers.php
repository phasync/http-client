<?php

use phasync\HttpClient\HttpClient;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

// Array data is encoded as JSON when the Content-Type header says so
$response = $client->request('PATCH', "$base/echo", ['done' => true], [
    'headers' => ['Content-Type: application/json', 'Authorization: Bearer secret-token'],
]);
$echo = json_decode((string) $response->getBody(), true);
echo $echo['method'], ' ', $echo['body'], "\n";
echo $echo['headers']['authorization'], "\n";

// For GET, the data goes in the query string
$response = $client->request('GET', "$base/echo", ['q' => 'phasync', 'page' => 2]);
echo json_encode(json_decode((string) $response->getBody(), true)['query']), "\n";
