<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\RequestException;
use phasync\Psr\Request;

$client = new HttpClient();

foreach (['foo://example.com/', 'http:///no-host'] as $url) {
    try {
        $client->sendRequest(Request::create('GET', $url));
    } catch (RequestException $e) {
        // the request was invalid: nothing went over the network
        echo $e->getMessage(), "\n";
    }
}
