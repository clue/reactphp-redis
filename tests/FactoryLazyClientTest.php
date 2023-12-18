<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Factory;
use React\Promise;

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
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->factory = new Factory($this->loop, $this->connector);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testWillConnectWithDefaultPort()
    {
        $this->connector->expects($this->never())->method('connect');
        $this->factory->createLazyClient('redis.example.com');
    }

    public function testWillConnectToLocalhost()
    {
        $this->connector->expects($this->never())->method('connect');
        $this->factory->createLazyClient('localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->never())->method('connect')->willReturn(Promise\resolve($stream));
        $redis = $this->factory->createLazyClient('localhost');

        $this->assertInstanceOf('Clue\React\Redis\Client', $redis);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->never())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://127.0.0.1/demo');
    }

    public function testWillWriteSelectCommandIfTargetContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$1\r\n4\r\n");

        $this->connector->expects($this->never())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://127.0.0.1?db=4');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsEncodedUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://:h%40llo@example.com');
    }

    public function testWillWriteAuthCommandIfTargetContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$6\r\nsecret\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://example.com?password=secret');
    }

    public function testWillWriteAuthCommandIfTargetContainsEncodedPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis://example.com?password=h%40llo');
    }

    public function testWillWriteAuthCommandIfRedissUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('tls://example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('rediss://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis+unix:///tmp/redis.sock?password=world');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis+unix://hello:world@/tmp/redis.sock');
    }

    public function testWillWriteSelectCommandIfRedisUnixUriContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->never())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createLazyClient('redis+unix:///tmp/redis.sock?db=demo');
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->never())->method('connect');
        $redis = $this->factory->createLazyClient('redis://127.0.0.1:2');

        $this->assertInstanceOf('Clue\React\Redis\Client', $redis);
    }

    public function testWillRejectIfTargetIsInvalid()
    {
        $redis = $this->factory->createLazyClient('http://invalid target');

        $this->assertInstanceOf('Clue\React\Redis\Client', $redis);
    }
}
