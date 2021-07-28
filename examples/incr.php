<?php

use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();

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
