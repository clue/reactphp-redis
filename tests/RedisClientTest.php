<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\RedisClient;
use Clue\React\Redis\Io\Factory;
use Clue\React\Redis\Io\StreamingClient;
use PHPUnit\Framework\MockObject\MockObject;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;

class RedisClientTest extends TestCase
{
    /** @var MockObject */
    private $factory;

    /** @var MockObject */
    private $loop;

    /** @var RedisClient */
    private $redis;

    public function setUp(): void
    {
        $this->factory = $this->createMock(Factory::class);
        $this->loop = $this->createMock(LoopInterface::class);

        $this->redis = new RedisClient('localhost', null, $this->loop);

        $ref = new \ReflectionProperty($this->redis, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($this->redis, $this->factory);
    }

    public function testPingWillCreateUnderlyingClientAndReturnPendingPromise()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->redis->ping();

        $promise->then($this->expectCallableNever());
    }

    public function testPingTwiceWillCreateOnceUnderlyingClient()
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->ping();
        $this->redis->ping();
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimer()
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());

        $promise = $this->redis->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimerWithIdleTimeFromQueryParam()
    {
        $this->redis = new RedisClient('localhost?idle=10', null, $this->loop);

        $ref = new \ReflectionProperty($this->redis, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($this->redis, $this->factory);

        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer')->with(10.0, $this->anything());

        $promise = $this->redis->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndNotStartIdleTimerWhenIdleParamIsNegative()
    {
        $this->redis = new RedisClient('localhost?idle=-1', null, $this->loop);

        $ref = new \ReflectionProperty($this->redis, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($this->redis, $this->factory);

        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->redis->ping();
        $deferred->resolve($client);

        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testPingWillRejectWhenUnderlyingClientRejectsPingAndStartIdleTimer()
    {
        $error = new \RuntimeException();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\reject($error));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->loop->expects($this->once())->method('addTimer');

        $promise = $this->redis->ping();
        $deferred->resolve($client);

        $promise->then(null, $this->expectCallableOnceWith($error));
    }

    public function testPingWillRejectAndNotEmitErrorOrCloseWhenFactoryRejectsUnderlyingClient()
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->on('error', $this->expectCallableNever());
        $this->redis->on('close', $this->expectCallableNever());

        $promise = $this->redis->ping();
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

        $this->redis->ping();
        $deferred->reject($error);

        $this->redis->ping();
    }

    public function testPingAfterPreviousUnderlyingClientAlreadyClosedWillCreateNewUnderlyingConnection()
    {
        $closeHandler = null;
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->any())->method('on')->withConsecutive(
            ['close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            })]
        );

        $this->factory->expects($this->exactly(2))->method('createClient')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($client),
            new Promise(function () { })
        );

        $this->redis->ping();
        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();

        $this->redis->ping();
    }

    public function testPingAfterCloseWillRejectWithoutCreatingUnderlyingConnection()
    {
        $this->factory->expects($this->never())->method('createClient');

        $this->redis->close();
        $promise = $this->redis->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closed (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testPingAfterPingWillNotStartIdleTimerWhenFirstPingResolves()
    {
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $this->redis->ping();
        $this->redis->ping();
        $deferred->resolve(null);
    }

    public function testPingAfterPingWillStartAndCancelIdleTimerWhenSecondPingStartsAfterFirstResolves()
    {
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->exactly(2))->method('__call')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->redis->ping();
        $deferred->resolve(null);
        $this->redis->ping();
    }

    public function testPingFollowedByIdleTimerWillCloseUnderlyingConnectionWithoutCloseEvent()
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timeout = null;
        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $this->redis->on('close', $this->expectCallableNever());

        $this->redis->ping();

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testCloseWillEmitCloseEventWithoutCreatingUnderlyingClient()
    {
        $this->factory->expects($this->never())->method('createClient');

        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
    }

    public function testCloseTwiceWillEmitCloseEventOnce()
    {
        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
        $this->redis->close();
    }

    public function testCloseAfterPingWillCancelUnderlyingClientConnectionWhenStillPending()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->ping();
        $this->redis->close();
    }

    public function testCloseAfterPingWillEmitCloseWithoutErrorWhenUnderlyingClientConnectionThrowsDueToCancellation()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException('Discarded');
        });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->on('error', $this->expectCallableNever());
        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->ping();
        $this->redis->close();
    }

    public function testCloseAfterPingWillCloseUnderlyingClientConnectionWhenAlreadyResolved()
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));
        $client->expects($this->once())->method('close');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);
        $this->redis->close();
    }

    public function testCloseAfterPingWillCancelIdleTimerWhenPingIsAlreadyResolved()
    {
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close');

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->redis->ping();
        $deferred->resolve(null);
        $this->redis->close();
    }

    public function testCloseAfterPingRejectsWillEmitClose()
    {
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close')->willReturnCallback(function () use ($client) {
            $client->emit('close');
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $ref = $this->redis;
        $ref->ping()->then(null, function () use ($ref, $client) {
            $ref->close();
        });
        $ref->on('close', $this->expectCallableOnce());
        $deferred->reject(new \RuntimeException());
    }

    public function testEndWillCloseClientIfUnderlyingConnectionIsNotPending()
    {
        $this->redis->on('close', $this->expectCallableOnce());
        $this->redis->end();
    }

    public function testEndAfterPingWillEndUnderlyingClient()
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('end');

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);
        $this->redis->end();
    }

    public function testEndAfterPingWillCloseClientWhenUnderlyingClientEmitsClose()
    {
        $closeHandler = null;
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('ping')->willReturn(\React\Promise\resolve('PONG'));
        $client->expects($this->once())->method('end');
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$closeHandler) {
            if ($event === 'close') {
                $closeHandler = $callback;
            }
        });

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);

        $this->redis->on('close', $this->expectCallableOnce());
        $this->redis->end();

        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();
    }

    public function testEmitsNoErrorEventWhenUnderlyingClientEmitsError()
    {
        $error = new \RuntimeException();

        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);

        $this->redis->on('error', $this->expectCallableNever());
        $client->emit('error', [$error]);
    }

    public function testEmitsNoCloseEventWhenUnderlyingClientEmitsClose()
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);

        $this->redis->on('close', $this->expectCallableNever());
        $client->emit('close');
    }

    public function testEmitsNoCloseEventButWillCancelIdleTimerWhenUnderlyingConnectionEmitsCloseAfterPingIsAlreadyResolved()
    {
        $closeHandler = null;
        $client = $this->createMock(StreamingClient::class);
        $deferred = new Deferred();
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->any())->method('on')->withConsecutive(
            ['close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            })]
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->redis->on('close', $this->expectCallableNever());

        $this->redis->ping();
        $deferred->resolve(null);

        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();
    }

    public function testEmitsMessageEventWhenUnderlyingClientEmitsMessageForPubSubChannel()
    {
        $messageHandler = null;
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$messageHandler) {
            if ($event === 'message') {
                $messageHandler = $callback;
            }
        });

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->subscribe('foo');
        $deferred->resolve($client);

        $this->redis->on('message', $this->expectCallableOnce());
        $this->assertTrue(is_callable($messageHandler));
        $messageHandler('foo', 'bar');
    }

    public function testEmitsUnsubscribeAndPunsubscribeEventsWhenUnderlyingClientClosesWhileUsingPubSubChannel()
    {
        $allHandler = null;
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->exactly(6))->method('__call')->willReturn(\React\Promise\resolve(null));
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$allHandler) {
            if (!isset($allHandler[$event])) {
                $allHandler[$event] = $callback;
            }
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->redis->subscribe('foo');
        $this->assertTrue(is_callable($allHandler['subscribe']));
        $allHandler['subscribe']('foo', 1);

        $this->redis->subscribe('bar');
        $this->assertTrue(is_callable($allHandler['subscribe']));
        $allHandler['subscribe']('bar', 2);

        $this->redis->unsubscribe('bar');
        $this->assertTrue(is_callable($allHandler['unsubscribe']));
        $allHandler['unsubscribe']('bar', 1);

        $this->redis->psubscribe('foo*');
        $this->assertTrue(is_callable($allHandler['psubscribe']));
        $allHandler['psubscribe']('foo*', 1);

        $this->redis->psubscribe('bar*');
        $this->assertTrue(is_callable($allHandler['psubscribe']));
        $allHandler['psubscribe']('bar*', 2);

        $this->redis->punsubscribe('bar*');
        $this->assertTrue(is_callable($allHandler['punsubscribe']));
        $allHandler['punsubscribe']('bar*', 1);

        $this->redis->on('unsubscribe', $this->expectCallableOnce());
        $this->redis->on('punsubscribe', $this->expectCallableOnce());

        $this->assertTrue(is_callable($allHandler['close']));
        $allHandler['close']();
    }

    public function testSubscribeWillResolveWhenUnderlyingClientResolvesSubscribeAndNotStartIdleTimerWithIdleDueToSubscription()
    {
        $subscribeHandler = null;
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('subscribe')->willReturn($deferred->promise());
        $client->expects($this->any())->method('on')->willReturnCallback(function ($event, $callback) use (&$subscribeHandler) {
            if ($event === 'subscribe' && $subscribeHandler === null) {
                $subscribeHandler = $callback;
            }
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->redis->subscribe('foo');
        $this->assertTrue(is_callable($subscribeHandler));
        $subscribeHandler('foo', 1);
        $deferred->resolve(['subscribe', 'foo', 1]);

        $promise->then($this->expectCallableOnceWith(['subscribe', 'foo', 1]));
    }

    public function testUnsubscribeAfterSubscribeWillResolveWhenUnderlyingClientResolvesUnsubscribeAndStartIdleTimerWhenSubscriptionStopped()
    {
        $subscribeHandler = null;
        $unsubscribeHandler = null;
        $deferredSubscribe = new Deferred();
        $deferredUnsubscribe = new Deferred();
        $client = $this->createMock(StreamingClient::class);
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

        $promise = $this->redis->subscribe('foo');
        $this->assertTrue(is_callable($subscribeHandler));
        $subscribeHandler('foo', 1);
        $deferredSubscribe->resolve(['subscribe', 'foo', 1]);
        $promise->then($this->expectCallableOnceWith(['subscribe', 'foo', 1]));

        $promise = $this->redis->unsubscribe('foo');
        $this->assertTrue(is_callable($unsubscribeHandler));
        $unsubscribeHandler('foo', 0);
        $deferredUnsubscribe->resolve(['unsubscribe', 'foo', 0]);
        $promise->then($this->expectCallableOnceWith(['unsubscribe', 'foo', 0]));
    }

    public function testBlpopWillRejectWhenUnderlyingClientClosesWhileWaitingForResponse()
    {
        $closeHandler = null;
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->with('blpop')->willReturn($deferred->promise());
        $client->expects($this->any())->method('on')->withConsecutive(
            ['close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            })]
        );

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->redis->blpop('list');

        $this->assertTrue(is_callable($closeHandler));
        $closeHandler();

        $deferred->reject($e = new \RuntimeException());

        $promise->then(null, $this->expectCallableOnceWith($e));
    }
}
