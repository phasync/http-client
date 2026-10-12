<?php

// The local server of the examples: a few endpoints that answer what they were sent
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ('/hello' === $path) {
    header('Content-Type: text/plain');
    echo "Hello, world\n";
} elseif ('/slow' === $path) {   // /slow?seconds=0.1&name=a
    usleep((int) (($_GET['seconds'] ?? 0.1) * 1_000_000));
    echo $_GET['name'] ?? 'done';
} elseif ('/stream' === $path) {
    header('Content-Type: text/plain');
    for ($i = 1; $i <= 3; ++$i) {
        echo "line $i\n";
        flush();
        usleep(10_000);
    }
} elseif (preg_match('#^/status/(\d+)$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo "status {$m[1]}\n";
} elseif ('/loop' === $path) {
    header('Location: /loop', true, 302);
} elseif ('/echo' === $path) {
    header('Content-Type: application/json');
    $headers = [];
    foreach (['CONTENT_TYPE' => 'content-type', 'HTTP_USER_AGENT' => 'user-agent', 'HTTP_X_REQUEST_ID' => 'x-request-id', 'HTTP_AUTHORIZATION' => 'authorization'] as $key => $name) {
        if (isset($_SERVER[$key])) {
            $headers[$name] = $_SERVER[$key];
        }
    }
    echo json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'query'   => $_GET,
        'headers' => $headers,
        'form'    => $_POST,
        'files'   => array_map(static fn ($f) => ['name' => $f['name'], 'size' => $f['size']], $_FILES),
        'body'    => $_POST || $_FILES ? '' : file_get_contents('php://input'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    http_response_code(404);
    echo "not found\n";
}
