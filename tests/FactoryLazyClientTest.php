<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

class FactoryLazyClientTest extends TestCase
{
    private $loop;
    private $connector;
    private $factory;

    /**
     * @before
     */
    public function setUpFactory()
    {
        $this->loop = $this->createMock(LoopInterface::class);
        $this->connector = $this->createMock(ConnectorInterface::class);
        $this->factory = new Factory($this->loop, $this->connector);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testWillConnectWithDefaultPort()
    {
        $this->connector->expects($this->never())->method('connect')->with('redis.example.com:6379')->willReturn(reject(new \RuntimeException()));
        $this->factory->createLazyClient('redis.example.com');
    }

    public function testWillConnectToLocalhost()
    {
        $this->connector->expects($this->never())->method('connect')->with('localhost:1337')->willReturn(reject(new \RuntimeException()));
        $this->factory->createLazyClient('localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->never())->method('connect')->willReturn(resolve($stream));
        $redis = $this->factory->createLazyClient('localhost');

        $this->assertInstanceOf(Client::class, $redis);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->never())->method('connect')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://127.0.0.1/demo');
    }

    public function testWillWriteSelectCommandIfTargetContainsDbQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$1\r\n4\r\n");

        $this->connector->expects($this->never())->method('connect')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://127.0.0.1?db=4');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsEncodedUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://:h%40llo@example.com');
    }

    public function testWillWriteAuthCommandIfTargetContainsPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$6\r\nsecret\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://example.com?password=secret');
    }

    public function testWillWriteAuthCommandIfTargetContainsEncodedPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis://example.com?password=h%40llo');
    }

    public function testWillWriteAuthCommandIfRedissUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('tls://example.com:6379')->willReturn(resolve($stream));
        $this->factory->createLazyClient('rediss://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis+unix:///tmp/redis.sock?password=world');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis+unix://hello:world@/tmp/redis.sock');
    }

    public function testWillWriteSelectCommandIfRedisUnixUriContainsDbQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createLazyClient('redis+unix:///tmp/redis.sock?db=demo');
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->never())->method('connect')->with('127.0.0.1:2')->willReturn(reject(new \RuntimeException()));
        $redis = $this->factory->createLazyClient('redis://127.0.0.1:2');

        $this->assertInstanceOf(Client::class, $redis);
    }

    public function testWillRejectIfTargetIsInvalid()
    {
        $redis = $this->factory->createLazyClient('http://invalid target');

        $this->assertInstanceOf(Client::class, $redis);
    }
}
