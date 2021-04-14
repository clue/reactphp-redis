<?php

use Clue\React\Redis\Factory;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();

$channel = isset($argv[1]) ? $argv[1] : 'channel';
$message = isset($argv[2]) ? $argv[2] : 'message';

$client = $factory->createLazyClient('localhost');
$client->publish($channel, $message)->then(function ($received) {
    echo 'Successfully published. Received by ' . $received . PHP_EOL;
}, function (Exception $e) {
    echo 'Unable to publish: ' . $e->getMessage() . PHP_EOL;
    if ($e->getPrevious()) {
        echo $e->getPrevious()->getMessage() . PHP_EOL;
    }
    exit(1);
});

$client->end();

Loop::get()->run();
