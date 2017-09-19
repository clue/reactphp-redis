<?php

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$channel = isset($argv[1]) ? $argv[1] : 'channel';
$message = isset($argv[2]) ? $argv[2] : 'message';

$factory->createClient('localhost')->then(function (Client $client) use ($channel, $message) {
    $client->publish($channel, $message)->then(function ($received) {
        echo 'successfully published. Received by ' . $received . PHP_EOL;
    });

    $client->end();
});

$loop->run();
