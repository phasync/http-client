<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\HttpClientOptions;

$base = require __DIR__ . '/../server.php';

$defaults            = new HttpClientOptions();
$defaults->userAgent = 'inventory-sync/1.0';
$defaults->timeoutMs = 5000;
$client              = new HttpClient($defaults);

$echo = json_decode((string) $client->get("$base/echo")->getBody(), true);
echo $echo['headers']['user-agent'], "\n";

// Options given to one request apply on top of the defaults, for that request only
$echo = json_decode((string) $client->get("$base/echo", ['userAgent' => 'one-off/2.0'])->getBody(), true);
echo $echo['headers']['user-agent'], "\n";

$echo = json_decode((string) $client->get("$base/echo")->getBody(), true);
echo $echo['headers']['user-agent'], "\n";
