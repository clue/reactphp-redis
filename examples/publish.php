<?php

// $ php examples/publish.php
// $ REDIS_URI=localhost:6379 php examples/publish.php channel message

use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();
$client = $factory->createLazyClient(getenv('REDIS_URI') ?: 'localhost:6379');

$channel = isset($argv[1]) ? $argv[1] : 'channel';
$message = isset($argv[2]) ? $argv[2] : 'message';

$client->publish($channel, $message)->then(function ($received) {
    echo 'Successfully published. Received by ' . $received . PHP_EOL;
}, function (Exception $e) {
    echo 'Unable to publish: ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

$client->end();
