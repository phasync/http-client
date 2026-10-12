<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\MultipartStream;
use phasync\HttpClient\MultipartStreamInterface;
use phasync\Psr\Request;

$base = require __DIR__ . '/../server.php';

$body = new MultipartStream(['comment' => 'looks good'], 'b0undary');
var_dump($body instanceof MultipartStreamInterface);

// sendRequest() sets Content-Type, with the boundary, from the body
$request  = Request::create('POST', "$base/echo")->withBody($body);
$echo     = json_decode((string) (new HttpClient())->sendRequest($request)->getBody(), true);
echo $echo['headers']['content-type'], "\n";
echo json_encode($echo['form']), "\n";
