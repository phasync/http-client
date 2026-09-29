<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\MultipartStream;
use phasync\HttpClient\Tests\Support\TestServer;
use phasync\Psr\ResourceStream;
use phasync\Psr\StringStream;

test('a string request body is sent as-is', function () {
    $client   = new HttpClient();
    $response = $client->post(TestServer::baseUrl() . '/post', 'raw-payload');

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['raw'])->toBe('raw-payload');
});

test('a StreamInterface request body is sent as-is', function () {
    $client   = new HttpClient();
    $response = $client->post(TestServer::baseUrl() . '/post', new StringStream('abc=def'));

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['form'])->toBe(['abc' => 'def']);
});

test('a resource-backed stream request body is sent as-is', function () {
    $tmp = \tmpfile();
    \fwrite($tmp, 'from=resource');
    \rewind($tmp);

    $client   = new HttpClient();
    $response = $client->post(TestServer::baseUrl() . '/post', new ResourceStream($tmp));

    $body = \json_decode((string) $response->getBody(), true);
    expect($body['form'])->toBe(['from' => 'resource']);
});

test('a MultipartStream sends fields and an uploaded file', function () {
    $tmp = \tempnam(\sys_get_temp_dir(), 'phasync-http-client-test-');
    \file_put_contents($tmp, 'file-contents-here');

    try {
        $body = new MultipartStream([
            'title' => 'hello world',
            'tags'  => ['a', 'b'],
            'file'  => \fopen($tmp, 'r'),
        ]);

        expect($body->getContentType())->toStartWith('multipart/form-data; boundary=');

        $client   = new HttpClient();
        $response = $client->post(TestServer::baseUrl() . '/post', $body);

        $data = \json_decode((string) $response->getBody(), true);
        expect($data['form'])->toBe(['title' => 'hello world', 'tags' => ['a', 'b']]);
        expect($data['files']['file']['content'])->toBe('file-contents-here');
        expect($data['headers']['content-type'])->toStartWith('multipart/form-data; boundary=');
    } finally {
        \unlink($tmp);
    }
});

test('response bodies are read progressively via the StreamInterface', function () {
    $client   = new HttpClient();
    $response = $client->get(TestServer::baseUrl() . '/stream/20');

    $stream = $response->getBody();
    $reads  = 0;
    $data   = '';
    while (!$stream->eof()) {
        $chunk = $stream->read(16);
        if ('' === $chunk) {
            break;
        }
        $data .= $chunk;
        ++$reads;
    }

    $expected = \implode('', \array_map(static fn ($i) => "chunk-{$i}\n", \range(0, 19)));
    expect($data)->toBe($expected);
    // More than one read() call was needed - the body was not handed back
    // as a single already-buffered chunk.
    expect($reads)->toBeGreaterThan(1);
});
