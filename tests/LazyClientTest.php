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
    private $loop;
    private $client;

    public function setUp()
    {
        $this->factory = $this->getMockBuilder('Clue\React\Redis\Factory')->disableOriginalConstructor()->getMock();
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->client = new LazyClient('localhost', $this->factory, $this->loop);
    }

    public function testPingWillCreateUnderlyingClientAndReturnPendingPromise()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->loop->expects($this->never())->method('addTimer');

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

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimer()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(60.0, $this->anything());

        $promise = $this->client->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimerWithIdleTimeFromQueryParam()
    {
        $this->client = new LazyClient('localhost?idle=10', $this->factory, $this->loop);

        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(10.0, $this->anything());

        $promise = $this->client->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndNotStartIdleTimerWhenIdleParamIsNegative()
    {
        $this->client = new LazyClient('localhost?idle=-1', $this->factory, $this->loop);

        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->client->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillRejectWhenUnderlyingClientRejectsPingAndStartIdleTimer()
    {
        $error = new \RuntimeException();
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\reject($error));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer');

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

    public function testPingAfterPingWillNotStartIdleTimerWhenFirstPingResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $this->client->ping();
        $this->client->ping();
        $deferred->resolve();
    }

    public function testPingAfterPingWillStartAndCancelIdleTimerWhenSecondPingStartsAfterFirstResolves()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->client->ping();
        $deferred->resolve();
        $this->client->ping();
    }

    public function testPingFollowedByIdleTimerWillCloseUnderlyingConnectionWithoutCloseEvent()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call', 'close'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());
        $client->expects($this->once())->method('close')->willReturn(\React\Promise\resolve());

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->client->on('close', $this->expectCallableNever());

        $this->client->ping();

        $this->assertNotNull($timeout);
        $timeout();
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

    public function testCloseAfterPingWillCancelIdleTimerWhenPingIsAlreadyResolved()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call', 'close'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->client->ping();
        $deferred->resolve();
        $this->client->close();
    }

    public function testCloseAfterPingRejectsWillEmitClose()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call', 'close'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close')->willReturnCallback(function () use ($client) {
            $client->emit('close');
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $ref = $this->client;
        $ref->ping()->then(null, function () use ($ref, $client) {
            $ref->close();
        });
        $ref->on('close', $this->expectCallableOnce());
        $deferred->reject(new \RuntimeException());
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

    public function testEmitsNoCloseEventButWillCancelIdleTimerWhenUnderlyingConnectionEmitsCloseAfterPingIsAlreadyResolved()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->client->on('close', $this->expectCallableNever());

        $this->client->ping();
        $deferred->resolve();

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

    public function testEmitsUnsubscribeAndPunsubscribeEventsWhenUnderlyingClientClosesWhileUsingPubSubChannel()
    {
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->exactly(6))->method('__call')->willReturn(\React\Promise\resolve());

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->client->subscribe('foo');
        $client->emit('subscribe', array('foo', 1));

        $this->client->subscribe('bar');
        $client->emit('subscribe', array('bar', 2));

        $this->client->unsubscribe('bar');
        $client->emit('unsubscribe', array('bar', 1));

        $this->client->psubscribe('foo*');
        $client->emit('psubscribe', array('foo*', 1));

        $this->client->psubscribe('bar*');
        $client->emit('psubscribe', array('bar*', 2));

        $this->client->punsubscribe('bar*');
        $client->emit('punsubscribe', array('bar*', 1));

        $this->client->on('unsubscribe', $this->expectCallableOnce());
        $this->client->on('punsubscribe', $this->expectCallableOnce());
        $client->emit('close');
    }

    public function testSubscribeWillResolveWhenUnderlyingClientResolvesSubscribeAndNotStartIdleTimerWithIdleDueToSubscription()
    {
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->once())->method('__call')->with('subscribe')->willReturn($deferred->promise());

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->client->subscribe('foo');
        $client->emit('subscribe', array('foo', 1));
        $deferred->resolve(array('subscribe', 'foo', 1));

        $promise->then($this->expectCallableOnceWith(array('subscribe', 'foo', 1)));
    }

    public function testUnsubscribeAfterSubscribeWillResolveWhenUnderlyingClientResolvesUnsubscribeAndStartIdleTimerWhenSubscriptionStopped()
    {
        $deferredSubscribe = new Deferred();
        $deferredUnsubscribe = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods(array('__call'))->getMock();
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls($deferredSubscribe->promise(), $deferredUnsubscribe->promise());

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->once())->method('addTimer');

        $promise = $this->client->subscribe('foo');
        $client->emit('subscribe', array('foo', 1));
        $deferredSubscribe->resolve(array('subscribe', 'foo', 1));
        $promise->then($this->expectCallableOnceWith(array('subscribe', 'foo', 1)));

        $promise = $this->client->unsubscribe('foo');
        $client->emit('unsubscribe', array('foo', 0));
        $deferredUnsubscribe->resolve(array('unsubscribe', 'foo', 0));
        $promise->then($this->expectCallableOnceWith(array('unsubscribe', 'foo', 0)));
    }
}
