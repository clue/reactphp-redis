<?php

// $ php examples/incr.php
// $ REDIS_URI=localhost:6379 php examples/incr.php

require __DIR__ . '/../vendor/autoload.php';

$redis = new Clue\React\Redis\RedisClient(getenv('REDIS_URI') ?: 'localhost:6379');

$redis->incr('test');

$redis->get('test')->then(function (string $result) {
    var_dump($result);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
});

$redis->end();
