<?php

use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;

/**
 * Measures how long one `/delay/$seconds` request takes on this machine
 * right now, so the assertions below compare against a live baseline
 * instead of a fixed wall-clock threshold - this machine can be busy with
 * unrelated load, and the client's cURL-multi service is shared static
 * state across tests running in the same process.
 */
function measureSingleDelayRequest(HttpClient $client, float $seconds): float
{
    $t = \microtime(true);
    $client->get(TestServer::baseUrl() . '/delay/' . $seconds)->getStatusCode();

    return \microtime(true) - $t;
}

/**
 * Retries a flaky timing assertion a few times before giving up, since
 * unrelated load on a shared machine can occasionally slow down even a
 * correctly concurrent run.
 */
function retryFlakyTiming(Closure $attempt, int $times = 3): void
{
    for ($i = 1; $i <= $times; ++$i) {
        try {
            $attempt();

            return;
        } catch (Throwable $e) {
            if ($i === $times) {
                throw $e;
            }
        }
    }
}

test('concurrent GET requests overlap in time (outside a coroutine)', function () {
    retryFlakyTiming(function () {
        $client   = new HttpClient();
        $baseline = measureSingleDelayRequest($client, 1);

        $t = \microtime(true);
        // Neither request actually starts fetching until its body is read, so
        // both are queued before either one blocks - they should run together
        // under one curl_multi batch.
        $a = $client->get(TestServer::baseUrl() . '/delay/1');
        $b = $client->get(TestServer::baseUrl() . '/delay/1');

        $bodyA   = \json_decode((string) $a->getBody(), true);
        $bodyB   = \json_decode((string) $b->getBody(), true);
        $elapsed = \microtime(true) - $t;

        expect($bodyA)->toBe(['delayed' => 1]);
        expect($bodyB)->toBe(['delayed' => 1]);
        // Two concurrent requests must stay well under twice the baseline (a
        // serial implementation would take roughly 2x).
        expect($elapsed)->toBeLessThan($baseline * 1.6);
    });
});

test('several sendRequest() calls in coroutines finish in overlapping time', function () {
    retryFlakyTiming(function () {
        $client   = new HttpClient();
        $baseline = measureSingleDelayRequest($client, 1);

        $t = \microtime(true);
        phasync::run(function () use ($client) {
            $fibers = [];
            for ($i = 0; $i < 3; ++$i) {
                $fibers[] = phasync::go(function () use ($client, $i) {
                    $response = $client->get(TestServer::baseUrl() . '/delay/1?n=' . $i);

                    return \json_decode((string) $response->getBody(), true);
                });
            }

            foreach ($fibers as $fiber) {
                expect(phasync::await($fiber))->toBe(['delayed' => 1]);
            }
        });
        $elapsed = \microtime(true) - $t;

        // 3 coroutines each sleeping ~1s on the server must overlap; a serial
        // implementation would take roughly 3x the baseline.
        expect($elapsed)->toBeLessThan($baseline * 2.2);
    });
});

test('a slow request runs concurrently with a fast one, not after it', function () {
    retryFlakyTiming(function () {
        $client   = new HttpClient();
        $baseline = measureSingleDelayRequest($client, 1);

        $t = \microtime(true);
        phasync::run(function () use ($client) {
            $slow = phasync::go(function () use ($client) {
                return $client->get(TestServer::baseUrl() . '/delay/1')->getStatusCode();
            });
            $fast = phasync::go(function () use ($client) {
                return $client->get(TestServer::baseUrl() . '/get')->getStatusCode();
            });

            expect(phasync::await($slow))->toBe(200);
            expect(phasync::await($fast))->toBe(200);
        });

        // If the fast request had to wait for the slow one (or vice versa) this
        // would take roughly 2x the baseline instead of ~1x.
        expect(\microtime(true) - $t)->toBeLessThan($baseline * 1.6);
    });
});

test('sendAsyncRequest() is lazy: concurrent PSR-7 requests overlap outside a coroutine', function () {
    retryFlakyTiming(function () {
        $client   = new HttpClient();
        $baseline = measureSingleDelayRequest($client, 1);

        $t = \microtime(true);
        $a = $client->sendAsyncRequest(phasync\Psr\Request::create('GET', TestServer::baseUrl() . '/delay/1'));
        $b = $client->sendAsyncRequest(phasync\Psr\Request::create('GET', TestServer::baseUrl() . '/delay/1'));
        expect(\microtime(true) - $t)->toBeLessThan(0.2);
        expect($a->getStatusCode())->toBe(200);
        expect($b->getStatusCode())->toBe(200);
        expect(\microtime(true) - $t)->toBeLessThan($baseline * 1.6);
    });
});

test('sendAsyncRequest() throws a PSR-18 exception on the first read, not when called', function () {
    $client   = new HttpClient();
    $response = $client->sendAsyncRequest(phasync\Psr\Request::create('GET', 'http://127.0.0.1:' . TestServer::unusedPort() . '/get'));

    try {
        $response->getStatusCode();
        $failure = null;
    } catch (Throwable $e) {
        $failure = $e;
    }
    expect($failure)->toBeInstanceOf(Psr\Http\Client\NetworkExceptionInterface::class);
});
