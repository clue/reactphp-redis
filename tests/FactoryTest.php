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

    public function testWillConnectToLocalIpWithDefaultPortIfTargetIsNotGiven()
    {
        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 6379)->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient();
    }

    public function testWillConnectWithDefaultPort()
    {
        $this->connector->expects($this->once())->method('create')->with('redis.example.com', 6379)->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('redis.example.com');
    }

    public function testWillConnectToLocalIpWhenTargetIsLocalhost()
    {
        $this->connector->expects($this->once())->method('create')->with('127.0.0.1', 1337)->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('tcp://localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('create')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient();

        $this->expectPromiseResolve($promise);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('create')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('tcp://127.0.0.1/demo');
    }

    public function testWillWriteAuthCommandIfTargetContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$11\r\nhello:world\r\n");

        $this->connector->expects($this->once())->method('create')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('tcp://hello:world@127.0.0.1');
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
