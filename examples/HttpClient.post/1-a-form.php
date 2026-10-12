<?php

use phasync\HttpClient\HttpClient;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

// An array is sent form-encoded unless a Content-Type header says otherwise
$response = $client->post("$base/echo", ['name' => 'Ada', 'tags' => ['math', 'engines']]);
$echo     = json_decode((string) $response->getBody(), true);

echo $echo['headers']['content-type'], "\n";
echo json_encode($echo['form']), "\n";
