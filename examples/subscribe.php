<?php

// $ php examples/subscribe.php
// $ REDIS_URI=localhost:6379 php examples/subscribe.php channel

use Clue\React\Redis\Factory;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();
$client = $factory->createLazyClient(getenv('REDIS_URI') ?: 'localhost:6379');

$channel = isset($argv[1]) ? $argv[1] : 'channel';

$client->subscribe($channel)->then(function () {
    echo 'Now subscribed to channel ' . PHP_EOL;
}, function (Exception $e) use ($client) {
    $client->close();
    echo 'Unable to subscribe: ' . $e->getMessage() . PHP_EOL;
});

$client->on('message', function ($channel, $message) {
    echo 'Message on ' . $channel . ': ' . $message . PHP_EOL;
});

// automatically re-subscribe to channel on connection issues
$client->on('unsubscribe', function ($channel) use ($client) {
    echo 'Unsubscribed from ' . $channel . PHP_EOL;

    Loop::addPeriodicTimer(2.0, function ($timer) use ($client, $channel){
        $client->subscribe($channel)->then(function () use ($timer) {
            echo 'Now subscribed again' . PHP_EOL;
            Loop::cancelTimer($timer);
        }, function (Exception $e) {
            echo 'Unable to subscribe again: ' . $e->getMessage() . PHP_EOL;
        });
    });
});
