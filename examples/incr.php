<?php

// $ php examples/incr.php
// $ REDIS_URI=localhost:6379 php examples/incr.php

use Clue\React\Redis\Factory;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Factory();
$client = $factory->createLazyClient(getenv('REDIS_URI') ?: 'localhost:6379');

$client->incr('test');

$client->get('test')->then(function ($result) {
    var_dump($result);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

//$client->end();
