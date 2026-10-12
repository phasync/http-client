<?php

/*
 * Starts a local `php -S` server (router.php) for an example, so the examples need no network,
 * and stops it when the example ends. `$base = require __DIR__ . '/../server.php';`
 */
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port   = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);

$server = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", __DIR__ . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
);
register_shutdown_function(static function () use ($server) {
    proc_terminate($server);
    proc_close($server);
});

for ($deadline = microtime(true) + 5; microtime(true) < $deadline; usleep(10_000)) {
    if ($fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
        fclose($fp);

        return "http://127.0.0.1:$port";
    }
}
throw new RuntimeException('The example server did not start');
