<?php

// $ php examples/cli.php
// $ REDIS_URI=localhost:6379 php examples/cli.php

use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$redis = new Clue\React\Redis\RedisClient(getenv('REDIS_URI') ?: 'localhost:6379');

Loop::addReadStream(STDIN, function () use ($redis) {
    $line = fgets(STDIN);
    if ($line === false || $line === '') {
        echo '# CTRL-D -> Ending connection...' . PHP_EOL;
        Loop::removeReadStream(STDIN);
        $redis->end();
        return;
    }

    $line = rtrim($line);
    if ($line === '') {
        return;
    }

    $args = explode(' ', $line);
    $command = strtolower(array_shift($args));

    // special method such as end() / close() called
    if (in_array($command, ['end', 'close'])) {
        $redis->$command();
        return;
    }

    $promise = $redis->callAsync($command, ...$args);

    $promise->then(function ($data): void {
        echo '# reply: ' . json_encode($data) . PHP_EOL;
    }, function (Throwable $e): void {
        echo '# error reply: ' . $e->getMessage() . PHP_EOL;
    });
});

$redis->on('close', function() {
    echo '## DISCONNECTED' . PHP_EOL;

    Loop::removeReadStream(STDIN);
});

echo '# Entering interactive mode ready, hit CTRL-D to quit' . PHP_EOL;
