<?php

use phasync\HttpClient\HttpClient;

$base   = require __DIR__ . '/../server.php';   // a local server for the example
$client = new HttpClient(['timeoutMs' => 5000]);

// Neither transfer has started yet: both start together at the first read
$slow = $client->get("$base/slow?seconds=0.2&name=slow");
$fast = $client->get("$base/slow?seconds=0.1&name=fast");

echo $slow->getStatusCode(), ' ', $slow->getBody(), "\n";
echo $fast->getStatusCode(), ' ', $fast->getBody(), "\n";
