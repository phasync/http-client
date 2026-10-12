<?php

use phasync\HttpClient\HttpClient;

$base   = require __DIR__ . '/../server.php';
$client = new HttpClient();

// Inside phasync::run() a request suspends only the coroutine that waits for it
phasync::run(function () use ($client, $base) {
    foreach (['b' => 0.2, 'a' => 0.1] as $name => $seconds) {
        phasync::go(function () use ($client, $base, $name, $seconds) {
            $body = (string) $client->get("$base/slow?seconds=$seconds&name=$name")->getBody();
            echo "response $body\n";
        });
    }
    echo "both requests sent\n";
});
