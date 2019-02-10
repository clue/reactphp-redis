<?php

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

/** @var Client $client */
$client = $factory->createLazyClient('localhost');
$client->incr('test');

$client->get('test')->then(function ($result) {
    var_dump($result);
});

$client->end();

$loop->run();
