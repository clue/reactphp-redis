<?php

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\React\Redis\ResponseApi;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$resolver = $factory->create('6.6.6.6', $loop);
$connector = new React\SocketClient\Connector($loop, $resolver);
$factory = new Factory($connector);

$factory->createClient()->then(function (Client $client) {
    $api = new ResponseApi($client);

    $api->incr('test');

    $api->get('test')->then(function ($result) {
        var_dump($result);
    });

    $api->end();
});

$loop->run();
