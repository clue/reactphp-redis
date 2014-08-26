<?php

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\Redis\Protocol\Model\StatusReply;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$factory->createClient()->then(function (Client $client) {
    $client->monitor()->then(function ($result) {
        echo 'Now monitoring all commands' . PHP_EOL;
    });

    $client->on('monitor', function (StatusReply $message) {
        echo 'Monitored: ' . $message->getValueNative() . PHP_EOL;
    });

    $client->echo('initial echo');
});

$loop->run();
