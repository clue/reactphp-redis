<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\LazyClient;
use Clue\React\Redis\StreamingClient;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\React\Redis\Client;
use React\EventLoop\Factory;
use React\Stream\ThroughStream;
use React\Promise\Promise;
use React\Promise\Deferred;

class LazyClientTest extends TestCase
{
    private $factory;
    private $client;

    public function setUp()
    {
        $this->factory = $this->getMockBuilder('Clue\React\Redis\Factory')->disableOriginalConstructor()->getMock();

        $this->client = new LazyClient('localhost', $this->factory);
    }

    public function testPingWillCreateUnderlyingClientAndReturnPendingPromise()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $promise = $this->client->ping();

        $promise->then($this->expectCallableNever());
    }

    public function testPingTwiceWillCreateOnceUnderlyingClient()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->client->ping();
        $this->client->ping();
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPing()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $promise = $this->client->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillRejectWhenUnderlyingClientRejectsPing()
    {
        $error = new \RuntimeException();
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\reject($error));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $promise = $this->client->ping();
        $deferred->resolve($client);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testPingWillRejectAndEmitErrorAndCloseWhenFactoryRejectsUnderlyingClient()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->on('error', $this->expectCallableOnceWith($error));
        $this->client->on('close', $this->expectCallableOnce());

        $promise = $this->client->ping();
        $deferred->reject($error);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testPingAfterCloseWillRejectWithoutCreatingUnderlyingConnection()
    {
        $this->factory->expects($this->never())->method('createClient');

        $this->client->close();
        $promise = $this->client->ping();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCloseWillEmitCloseEventWithoutCreatingUnderlyingClient()
    {
        $this->factory->expects($this->never())->method('createClient');

        $this->client->on('close', $this->expectCallableOnce());

        $this->client->close();
    }

    public function testCloseTwiceWillEmitCloseEventOnce()
    {
        $this->client->on('close', $this->expectCallableOnce());

        $this->client->close();
        $this->client->close();
    }

    public function testCloseAfterPingWillCancelUnderlyingClientConnectionWhenStillPending()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->client->ping();
        $this->client->close();
    }

    public function testCloseAfterPingWillEmitCloseWithoutErrorWhenUnderlyingClientConnectionThrowsDueToCancellation()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException('Discarded');
        });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->client->on('error', $this->expectCallableNever());
        $this->client->on('close', $this->expectCallableOnce());

        $this->client->ping();
        $this->client->close();
    }

    public function testCloseAfterPingWillCloseUnderlyingClientConnectionWhenAlreadyResolved()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('close');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);
        $this->client->close();
    }

    public function testEndWillCloseClientIfUnderlyingConnectionIsNotPending()
    {
        $this->client->on('close', $this->expectCallableOnce());
        $this->client->end();
    }

    public function testEndAfterPingWillEndUnderlyingClient()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('end');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);
        $this->client->end();
    }

    public function testEmitsErrorEventWhenUnderlyingClientEmitsError()
    {
        $error = new \RuntimeException();

        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('error', $this->expectCallableOnceWith($error));
        $client->emit('error', array($error));
    }

    public function testEmitsCloseEventWhenUnderlyingClientEmitsClose()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('close', $this->expectCallableOnce());
        $client->emit('close');
    }

    public function testEmitsMessageEventWhenUnderlyingClientEmitsMessageForPubSubChannel()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->subscribe('foo');
        $deferred->resolve($client);

        $this->client->on('message', $this->expectCallableOnce());
        $client->emit('message', array('foo', 'bar'));
    }
}
