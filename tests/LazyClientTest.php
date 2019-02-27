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

    public function testPingWillRejectAndNotEmitErrorOrCloseWhenFactoryRejectsUnderlyingClient()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->on('error', $this->expectCallableNever());
        $this->client->on('close', $this->expectCallableNever());

        $promise = $this->client->ping();
        $deferred->reject($error);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testPingAfterPreviousFactoryRejectsUnderlyingClientWillCreateNewUnderlyingConnection()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->exactly(2))->method('createClient')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->client->ping();
        $deferred->reject($error);

        $this->client->ping();
    }

    public function testPingAfterPreviousUnderlyingClientAlreadyClosedWillCreateNewUnderlyingConnection()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $this->factory->expects($this->exactly(2))->method('createClient')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
        );

        $this->client->ping();
        $client->emit('close');

        $this->client->ping();
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
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());
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

    public function testEndAfterPingWillCloseClientWhenUnderlyingClientEmitsClose()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call', 'end'))->getMock();
        //$client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('end');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('close', $this->expectCallableOnce());
        $this->client->end();

        $client->emit('close');
    }

    public function testEmitsNoErrorEventWhenUnderlyingClientEmitsError()
    {
        $error = new \RuntimeException();

        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('error', $this->expectCallableNever());
        $client->emit('error', array($error));
    }

    public function testEmitsNoCloseEventWhenUnderlyingClientEmitsClose()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('close', $this->expectCallableNever());
        $client->emit('close');
    }

    public function testEmitsMessageEventWhenUnderlyingClientEmitsMessageForPubSubChannel()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->subscribe('foo');
        $deferred->resolve($client);

        $this->client->on('message', $this->expectCallableOnce());
        $client->emit('message', array('foo', 'bar'));
    }
}
