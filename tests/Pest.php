<?php

use phasync\HttpClient\Tests\Support\TestServer;

TestServer::start();

\register_shutdown_function(static function () {
    TestServer::stop();
});
