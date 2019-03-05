<?php

use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$client = $factory->createLazyClient('localhost');
$client->incr('test');

$client->get('test')->then(function ($result) {
    var_dump($result);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    if ($e->getPrevious()) {
        echo $e->getPrevious()->getMessage() . PHP_EOL;
    }
    exit(1);
});

$client->end();

$loop->run();
