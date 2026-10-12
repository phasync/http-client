<?php

use phasync\HttpClient\HttpClient;

$base     = require __DIR__ . '/../server.php';
$response = (new HttpClient())->get("$base/stream");

echo get_class($response), "\n";
echo $response->getStatusCode(), "\n";   // waits for the headers only

$body = $response->getBody();
while (!$body->eof()) {
    $chunk = $body->read(1024);          // what has arrived so far
    if ('' !== $chunk) {
        echo 'read: ', json_encode($chunk), "\n";
    }
}
echo 'downloaded bytes: ', $response->getDownloadedBytes(), "\n";
