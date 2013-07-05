<?php

namespace Clue\Redis\React;

use React\Promise\When;

use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\Stream\Stream;
use Clue\Redis\React\Client;
use InvalidArgumentException;

class Factory
{
    private $loop;
    private $connector;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector)
    {
        $this->loop = $loop;
        $this->connector = $connector;
    }

    public function createClient($target = null)
    {
        $that = $this;
        return $this->connect($target)->then(function (Stream $stream) use ($that) {
            return new Client($stream, $that->createProtocol());
        });
    }

    public function createProtocol()
    {
        return ProtocolFactory::create();
    }

    private function connect($target)
    {
        if ($target === null) {
            $target = 'tcp://127.0.0.1:6379';
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['host']) || !isset($parts['port'])) {
            return When::reject(new InvalidArgumentException('Invalid target host given'));
        }

        return $this->connector->create($parts['host'], $parts['port']);
    }
}
