<?php

// $ php examples/publish.php
// $ REDIS_URI=localhost:6379 php examples/publish.php channel message

require __DIR__ . '/../vendor/autoload.php';

$factory = new Clue\React\Redis\Factory();
$redis = $factory->createLazyClient(getenv('REDIS_URI') ?: 'localhost:6379');

$channel = $argv[1] ?? 'channel';
$message = $argv[2] ?? 'message';

$redis->publish($channel, $message)->then(function ($received) {
    echo 'Successfully published. Received by ' . $received . PHP_EOL;
}, function (Exception $e) {
    echo 'Unable to publish: ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

$redis->end();
