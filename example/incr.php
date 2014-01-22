<?php

use Clue\Redis\React\Client;
use Clue\Redis\React\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$resolver = $factory->create('6.6.6.6', $loop);
$connector = new React\SocketClient\Connector($loop, $resolver);
$factory = new Factory($connector);

$factory->createClient()->then(function (Client $client) {
    $client->incr('test');

    $client->get('test')->then(function ($result) {
        var_dump($result);
    });

    $client->end();
});

$loop->run();
