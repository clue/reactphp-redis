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

    /**
     * @before
     */
    public function setUpClient()
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
        $closeHandler = null;
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->any())->method('on')->withConsecutive(
            array('close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            }))
        );

        $this->factory->expects($this->exactly(2))->method('createClient')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
        );

        $this->client->ping();
        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();

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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $closeHandler = null;
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('end');
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$closeHandler) {
            if ($event === 'close') {
                $closeHandler = $callback;
            }
        });

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->ping();
        $deferred->resolve($client);

        $this->client->on('close', $this->expectCallableOnce());
        $this->client->end();

        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();
    }

    public function testEmitsNoErrorEventWhenUnderlyingClientEmitsError()
    {
        $error = new \RuntimeException();

        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
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
        $closeHandler = null;
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $deferred = new Deferred();
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->any())->method('on')->withConsecutive(
            array('close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            }))
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->client->on('close', $this->expectCallableNever());

        $this->client->ping();
        $deferred->resolve();

        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();
    }

    public function testEmitsMessageEventWhenUnderlyingClientEmitsMessageForPubSubChannel()
    {
        $messageHandler = null;
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve());
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$messageHandler) {
            if ($event === 'message') {
                $messageHandler = $callback;
            }
        });

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->client->subscribe('foo');
        $deferred->resolve($client);

        $this->client->on('message', $this->expectCallableOnce());
        $this->assertTrue(is_callable($messageHandler));
        $messageHandler('foo', 'bar');
    }

    public function testEmitsUnsubscribeAndPunsubscribeEventsWhenUnderlyingClientClosesWhileUsingPubSubChannel()
    {
        $allHandler = null;
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->exactly(6))->method('__call')->willReturn(\React\Promise\resolve());
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$allHandler) {
            if (!isset($allHandler[$event])) {
                $allHandler[$event] = $callback;
            }
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->client->subscribe('foo');
        $this->assertTrue(is_callable($allHandler['subscribe']));
        $allHandler['subscribe']('foo', 1);

        $this->client->subscribe('bar');
        $this->assertTrue(is_callable($allHandler['subscribe']));
        $allHandler['subscribe']('bar', 2);

        $this->client->unsubscribe('bar');
        $this->assertTrue(is_callable($allHandler['unsubscribe']));
        $allHandler['unsubscribe']('bar', 1);

        $this->client->psubscribe('foo*');
        $this->assertTrue(is_callable($allHandler['psubscribe']));
        $allHandler['psubscribe']('foo*', 1);

        $this->client->psubscribe('bar*');
        $this->assertTrue(is_callable($allHandler['psubscribe']));
        $allHandler['psubscribe']('bar*', 2);

        $this->client->punsubscribe('bar*');
        $this->assertTrue(is_callable($allHandler['punsubscribe']));
        $allHandler['punsubscribe']('bar*', 1);

        $this->client->on('unsubscribe', $this->expectCallableOnce());
        $this->client->on('punsubscribe', $this->expectCallableOnce());

        $this->assertTrue(is_callable($allHandler['close']));
        $allHandler['close']();
    }

    public function testSubscribeWillResolveWhenUnderlyingClientResolvesSubscribeAndNotStartIdleTimerWithIdleDueToSubscription()
    {
        $subscribeHandler = null;
        $deferred = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->once())->method('__call')->with('subscribe')->willReturn($deferred->promise());
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$subscribeHandler) {
            if ($event === 'subscribe' && $subscribeHandler === null) {
                $subscribeHandler = $callback;
            }
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->client->subscribe('foo');
        $this->assertTrue(is_callable($subscribeHandler));
        $subscribeHandler('foo', 1);
        $deferred->resolve(array('subscribe', 'foo', 1));

        $promise->then($this->expectCallableOnceWith(array('subscribe', 'foo', 1)));
    }

    public function testUnsubscribeAfterSubscribeWillResolveWhenUnderlyingClientResolvesUnsubscribeAndStartIdleTimerWhenSubscriptionStopped()
    {
        $subscribeHandler = null;
        $unsubscribeHandler = null;
        $deferredSubscribe = new Deferred();
        $deferredUnsubscribe = new Deferred();
        $client = $this->getMockBuilder('Clue\React\Redis\Client')->getMock();
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls($deferredSubscribe->promise(), $deferredUnsubscribe->promise());
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$subscribeHandler, &$unsubscribeHandler) {
            if ($event === 'subscribe' && $subscribeHandler === null) {
                $subscribeHandler = $callback;
            }
            if ($event === 'unsubscribe' && $unsubscribeHandler === null) {
                $unsubscribeHandler = $callback;
            }
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->once())->method('addTimer');

        $promise = $this->client->subscribe('foo');
        $this->assertTrue(is_callable($subscribeHandler));
        $subscribeHandler('foo', 1);
        $deferredSubscribe->resolve(array('subscribe', 'foo', 1));
        $promise->then($this->expectCallableOnceWith(array('subscribe', 'foo', 1)));

        $promise = $this->client->unsubscribe('foo');
        $this->assertTrue(is_callable($unsubscribeHandler));
        $unsubscribeHandler('foo', 0);
        $deferredUnsubscribe->resolve(array('unsubscribe', 'foo', 0));
        $promise->then($this->expectCallableOnceWith(array('unsubscribe', 'foo', 0)));
    }

    public function createCallableMockWithOriginalConstructorDisabled($array)
    {
        if (method_exists('PHPUnit\Framework\MockObject\MockBuilder', 'addMethods')) {
            // PHPUnit 9+
            return $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->onlyMethods($array)->getMock();
        } else {
            // legacy PHPUnit 4 - PHPUnit 8
            return $this->getMockBuilder('Clue\React\Redis\StreamingClient')->disableOriginalConstructor()->setMethods($array)->getMock();
        }
    }
}
