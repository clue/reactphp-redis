<?php

use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\Redis\Protocol\Model\ModelInterface;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

echo '# connecting to redis...' . PHP_EOL;

$factory->createClient('localhost')->then(function (Client $client) use ($loop) {
    echo '# connected! Entering interactive mode, hit CTRL-D to quit' . PHP_EOL;

    $client->on('data', function (ModelInterface $data) {
        if ($data instanceof ErrorReply) {
            echo '# error reply: ' . $data->getMessage() . PHP_EOL;
        } else {
            echo '# reply: ' . json_encode($data->getValueNative()) . PHP_EOL;
        }
    });

    $loop->addReadStream(STDIN, function () use ($client, $loop) {
        $line = fgets(STDIN);
        if ($line === false || $line === '') {
            echo '# CTRL-D -> Ending connection...' . PHP_EOL;
            $client->end();
        } else {
            $line = rtrim($line);

            if ($line === '') {

            } else {
                $params = explode(' ', $line);
                $method = array_shift($params);
                call_user_func_array(array($client, $method), $params);
            }
        }
    });

    $client->on('close', function() use ($loop) {
        echo '## DISCONNECTED' . PHP_EOL;

        $loop->removeReadStream(STDIN);
    });
}, function (Exception $error) {
    echo 'CONNECTION ERROR: ' . $error->getMessage() . PHP_EOL;
    exit(1);
});


$loop->run();
