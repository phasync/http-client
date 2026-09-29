<?php

use phasync\CancelledException;
use phasync\HttpClient\HttpClient;
use phasync\HttpClient\Tests\Support\TestServer;

test('cancelling the coroutine running an in-flight request throws CancelledException', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $slow = phasync::go(function () use ($client) {
            return $client->get(TestServer::baseUrl() . '/delay/2')->getStatusCode();
        });

        // Give the request a chance to actually start (reach its curl-multi
        // suspension point) before cancelling it.
        phasync::sleep(0.1);
        phasync::cancel($slow);

        expect(fn () => phasync::await($slow))->toThrow(CancelledException::class);
    });
});

test('cancellation of one request does not disturb a concurrent one', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $victim = phasync::go(function () use ($client) {
            return $client->get(TestServer::baseUrl() . '/delay/2')->getStatusCode();
        });
        $survivor = phasync::go(function () use ($client) {
            return $client->get(TestServer::baseUrl() . '/delay/1')->getStatusCode();
        });

        phasync::sleep(0.1);
        phasync::cancel($victim);

        expect(fn () => phasync::await($victim))->toThrow(CancelledException::class);
        expect(phasync::await($survivor))->toBe(200);
    });
});

test('the client remains usable after a cancelled request', function () {
    $client = new HttpClient();

    phasync::run(function () use ($client) {
        $slow = phasync::go(function () use ($client) {
            return $client->get(TestServer::baseUrl() . '/delay/2')->getStatusCode();
        });

        phasync::sleep(0.1);
        phasync::cancel($slow);
        try {
            phasync::await($slow);
        } catch (CancelledException) {
        }

        $response = $client->get(TestServer::baseUrl() . '/get');
        expect($response->getStatusCode())->toBe(200);
    });
});
