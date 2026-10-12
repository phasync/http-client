<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\MultipartStream;

$base = require __DIR__ . '/../server.php';

$file = tmpfile();
fwrite($file, "id,name\n1,Ada\n");
rewind($file);

$body = new MultipartStream([
    'title'  => 'Customers',
    'tags'   => ['import', 'csv'],
    'upload' => $file,
], 'example-boundary');
echo $body->getContentType(), "\n";

$echo = json_decode((string) (new HttpClient())->post("$base/echo", $body)->getBody(), true);
echo json_encode($echo['form']), "\n";
echo 'file of ', $echo['files']['upload']['size'], " bytes\n";
