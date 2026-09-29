<?php

/**
 * Router for the local test server (`php -S 127.0.0.1:PORT tests/Support/router.php`)
 * used by the test suite instead of any real, internet-hosted service.
 */
$path = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH) ?: '/';

function test_server_headers(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (\str_starts_with($key, 'HTTP_')) {
            $name                        = \str_replace('_', '-', \substr($key, 5));
            $headers[\strtolower($name)] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
    }

    return $headers;
}

if (\preg_match('#^/delay/([0-9.]+)$#', $path, $m)) {
    \usleep((int) (((float) $m[1]) * 1_000_000));
    \header('Content-Type: application/json');
    echo \json_encode(['delayed' => (float) $m[1]]);
    exit;
}

if (\preg_match('#^/status/(\d+)$#', $path, $m)) {
    \http_response_code((int) $m[1]);
    exit;
}

if ('/redirect-to' === $path) {
    \http_response_code((int) ($_GET['status'] ?? 302));
    \header('Location: ' . ($_GET['url'] ?? '/get'));
    exit;
}

if (\preg_match('#^/redirect/(\d+)$#', $path, $m)) {
    $n = (int) $m[1];
    if ($n <= 0) {
        \header('Content-Type: application/json');
        echo \json_encode(['done' => true]);
        exit;
    }
    \http_response_code(302);
    \header('Location: /redirect/' . ($n - 1));
    exit;
}

if (\preg_match('#^/stream/(\d+)$#', $path, $m)) {
    $n = (int) $m[1];
    \header('Content-Type: text/plain');
    \header('X-Accel-Buffering: no');
    for ($i = 0; $i < $n; ++$i) {
        echo "chunk-{$i}\n";
        if (\function_exists('ob_flush')) {
            @\ob_flush();
        }
        \flush();
        \usleep(10_000);
    }
    exit;
}

if ('/headers' === $path) {
    \header('Content-Type: application/json');
    echo \json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'headers' => test_server_headers(),
    ]);
    exit;
}

if ('/get' === $path) {
    \header('Content-Type: application/json');
    echo \json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'query'   => $_GET,
        'headers' => test_server_headers(),
    ]);
    exit;
}

if (\in_array($path, ['/post', '/put', '/method'], true)) {
    $raw = \file_get_contents('php://input');
    \header('Content-Type: application/json');
    echo \json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'form'    => $_POST,
        'files'   => \array_map(
            static fn (array $f) => [
                'name'    => $f['name'],
                'size'    => $f['size'],
                'content' => \file_get_contents($f['tmp_name']),
            ],
            $_FILES
        ),
        'raw'     => $raw,
        'headers' => test_server_headers(),
    ]);
    exit;
}

\http_response_code(404);
\header('Content-Type: application/json');
echo \json_encode(['error' => 'not found', 'path' => $path]);
