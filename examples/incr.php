<?php

// $ php examples/incr.php
// $ REDIS_URI=localhost:6379 php examples/incr.php

require __DIR__ . '/../vendor/autoload.php';

$factory = new Clue\React\Redis\Factory();
$redis = $factory->createLazyClient(getenv('REDIS_URI') ?: 'localhost:6379');

$redis->incr('test');

$redis->get('test')->then(function ($result) {
    var_dump($result);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

//$redis->end();
