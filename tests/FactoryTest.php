<?php

use React\Socket\ConnectionInterface;

use Clue\React\Redis\Factory;
use React\Promise;

class FactoryTest extends TestCase
{
    private $loop;
    private $connector;
    private $factory;

    public function setUp()
    {
        $this->loop = $this->getMock('React\EventLoop\LoopInterface');
        $this->connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $this->factory = new Factory($this->loop, $this->connector);
    }

    public function testCtor()
    {
        $this->factory = new Factory($this->loop);
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 2)->willReturn(Promise\reject(new \RuntimeException()));
        $promise = $this->factory->createClient('tcp://127.0.0.1:2');

        $this->expectPromiseReject($promise);
    }

    public function testWillRejectIfTargetIsInvalid()
    {
        $promise = $this->factory->createClient('http://invalid target');

        $this->expectPromiseReject($promise);
    }
}
