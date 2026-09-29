<?php

namespace phasync\HttpClient\Tests\Support;

/**
 * Starts a local `php -S` server backed by {@see router.php} for the test
 * suite, so tests never depend on the internet.
 */
final class TestServer
{
    /** @var resource|null */
    private static $process;
    private static int $port = 0;

    public static function start(): void
    {
        if (null !== self::$process) {
            return;
        }

        self::$port = self::findFreePort();
        $router     = __DIR__ . '/router.php';
        $cmd        = \sprintf(
            '%s -d display_errors=0 -S 127.0.0.1:%d %s',
            \escapeshellarg(\PHP_BINARY),
            self::$port,
            \escapeshellarg($router)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        // PHP's built-in server handles one request at a time by default; spawn
        // worker processes so it can serve overlapping requests, which the
        // concurrency tests rely on.
        $env         = \array_merge(\getenv(), ['PHP_CLI_SERVER_WORKERS' => '8']);
        $process     = \proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to start the local test server');
        }
        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }
        self::$process = $process;

        $deadline = \microtime(true) + 5;
        while (\microtime(true) < $deadline) {
            $fp = @\fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if (false !== $fp) {
                \fclose($fp);

                return;
            }
            \usleep(20_000);
        }

        self::stop();
        throw new \RuntimeException('The local test server did not start in time');
    }

    public static function stop(): void
    {
        if (null !== self::$process) {
            \proc_terminate(self::$process);
            \proc_close(self::$process);
            self::$process = null;
        }
    }

    public static function baseUrl(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    /**
     * A port on 127.0.0.1 that nothing is listening on, for
     * "connection refused" tests. Not perfectly race-free, but good enough
     * for a local, single-purpose test run.
     */
    public static function unusedPort(): int
    {
        return self::findFreePort();
    }

    private static function findFreePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $socket) {
            throw new \RuntimeException("Unable to find a free port: {$errstr}");
        }
        $name = \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, \strrpos($name, ':') + 1);
    }
}
