<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\NetworkException;
use phasync\Psr\Request;

// A port nothing listens on
$socket = stream_socket_server('tcp://127.0.0.1:0');
$url    = 'http://' . stream_socket_get_name($socket, false) . '/';
fclose($socket);

try {
    (new HttpClient())->sendRequest(Request::create('GET', $url));
} catch (NetworkException $e) {
    echo get_class($e), "\n";
    echo 'cURL error ', $e->getCode(), ' (CURLE_COULDNT_CONNECT is ', CURLE_COULDNT_CONNECT, ")\n";
}
