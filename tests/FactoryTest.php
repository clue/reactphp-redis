<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Factory;
use React\Promise;
use React\Promise\Deferred;

class FactoryTest extends TestCase
{
    private $loop;
    private $connector;
    private $factory;

    public function setUp()
    {
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->factory = new Factory($this->loop, $this->connector);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCtor()
    {
        $this->factory = new Factory($this->loop);
    }

    public function testWillConnectWithDefaultPort()
    {
        $this->connector->expects($this->once())->method('connect')->with('redis.example.com:6379')->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('redis.example.com');
    }

    public function testWillConnectToLocalhost()
    {
        $this->connector->expects($this->once())->method('connect')->with('localhost:1337')->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('localhost');

        $this->expectPromiseResolve($promise);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://127.0.0.1/demo');
    }

    public function testWillWriteSelectCommandIfTargetContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$1\r\n4\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://127.0.0.1?db=4');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsEncodedUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://:h%40llo@example.com');
    }

    public function testWillWriteAuthCommandIfTargetContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$6\r\nsecret\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://example.com?password=secret');
    }

    public function testWillWriteAuthCommandIfTargetContainsEncodedPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://example.com?password=h%40llo');
    }

    public function testWillWriteAuthCommandIfRedissUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('tls://example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('rediss://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?password=world');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix://hello:world@/tmp/redis.sock');
    }

    public function testWillWriteSelectCommandIfRedisUnixUriContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?db=demo');
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn(Promise\reject(new \RuntimeException()));
        $promise = $this->factory->createClient('redis://127.0.0.1:2');

        $this->expectPromiseReject($promise);
    }

    public function testWillRejectIfTargetIsInvalid()
    {
        $promise = $this->factory->createClient('http://invalid target');

        $this->expectPromiseReject($promise);
    }

    public function testCancelWillRejectPromise()
    {
        $promise = new \React\Promise\Promise(function () { });
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($promise);

        $promise = $this->factory->createClient('redis://127.0.0.1:2');
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function testCancelWillCancelConnectorWhenConnectionIsPending()
    {
        $deferred = new Deferred($this->expectCallableOnce());
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($deferred->promise());

        $promise = $this->factory->createClient('redis://127.0.0.1:2');
        $promise->cancel();
    }

    public function testCancelWillCloseConnectionWhenConnectionWaitsForSelect()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write');
        $stream->expects($this->once())->method('close');

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));

        $promise = $this->factory->createClient('redis://127.0.0.1:2/123');
        $promise->cancel();
    }
}
