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

        assert($this->loop instanceof LoopInterface);
        $this->redis = new RedisClient('localhost', null, $this->loop);

        $ref = new \ReflectionProperty($this->redis, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($this->redis, $this->factory);
    }

    public function testPingWillCreateUnderlyingClientAndReturnPendingPromise(): void
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->loop->expects($this->never())->method('addTimer');

        $promise = $this->redis->ping();

        $promise->then($this->expectCallableNever());
    }

    public function testPingTwiceWillCreateOnceUnderlyingClient(): void
    {
        $promise = new Promise(function () { });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->ping();
        $this->redis->ping();
    }

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimer(): void
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

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndStartIdleTimerWithIdleTimeFromQueryParam(): void
    {
        assert($this->loop instanceof LoopInterface);
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

    public function testPingWillResolveWhenUnderlyingClientResolvesPingAndNotStartIdleTimerWhenIdleParamIsNegative(): void
    {
        assert($this->loop instanceof LoopInterface);
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

    public function testPingWillRejectWhenUnderlyingClientRejectsPingAndStartIdleTimer(): void
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

    public function testPingWillRejectAndNotEmitErrorOrCloseWhenFactoryRejectsUnderlyingClient(): void
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

    public function testPingAfterPreviousFactoryRejectsUnderlyingClientWillCreateNewUnderlyingConnection(): void
    {
        $error = new \RuntimeException();

        $deferred = new Deferred();
        $this->factory->expects($this->exactly(2))->method('createClient')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $promise = $this->redis->ping();

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $deferred->reject($error);

        $this->redis->ping();
    }

    public function testPingAfterPreviousUnderlyingClientAlreadyClosedWillCreateNewUnderlyingConnection(): void
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

    public function testPingAfterCloseWillRejectWithoutCreatingUnderlyingConnection(): void
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

    public function testPingAfterPingWillNotStartIdleTimerWhenFirstPingResolves(): void
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

    public function testPingAfterPingWillStartAndCancelIdleTimerWhenSecondPingStartsAfterFirstResolves(): void
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

    public function testPingFollowedByIdleTimerWillCloseUnderlyingConnectionWithoutCloseEvent(): void
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

    public function testCloseWillEmitCloseEventWithoutCreatingUnderlyingClient(): void
    {
        $this->factory->expects($this->never())->method('createClient');

        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
    }

    public function testCloseTwiceWillEmitCloseEventOnce(): void
    {
        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
        $this->redis->close();
    }

    public function testCloseAfterPingWillCancelUnderlyingClientConnectionWhenStillPending(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->ping();
        $this->redis->close();
    }

    public function testCloseAfterPingWillEmitCloseWithoutErrorWhenUnderlyingClientConnectionThrowsDueToCancellation(): void
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException('Discarded');
        });
        $this->factory->expects($this->once())->method('createClient')->willReturn($promise);

        $this->redis->on('error', $this->expectCallableNever());
        $this->redis->on('close', $this->expectCallableOnce());

        $promise = $this->redis->ping();

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->redis->close();
    }

    public function testCloseAfterPingWillCloseUnderlyingClientConnectionWhenAlreadyResolved(): void
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

    public function testCloseAfterPingWillCancelIdleTimerWhenPingIsAlreadyResolved(): void
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

    public function testCloseAfterPingRejectsWillEmitClose(): void
    {
        $deferred = new Deferred();
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn($deferred->promise());
        $client->expects($this->once())->method('close')->willReturnCallback(function () use ($client) {
            assert($client instanceof StreamingClient);
            $client->emit('close');
        });

        $this->factory->expects($this->once())->method('createClient')->willReturn(\React\Promise\resolve($client));

        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $this->redis->ping()->then(null, function () {
            $this->redis->close();
        });
        $this->redis->on('close', $this->expectCallableOnce());
        $deferred->reject(new \RuntimeException());
    }

    public function testEndWillCloseClientIfUnderlyingConnectionIsNotPending(): void
    {
        $this->redis->on('close', $this->expectCallableOnce());
        $this->redis->end();
    }

    public function testEndAfterPingWillEndUnderlyingClient(): void
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

    public function testEndAfterPingWillCloseClientWhenUnderlyingClientEmitsClose(): void
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

    public function testEmitsNoErrorEventWhenUnderlyingClientEmitsError(): void
    {
        $error = new \RuntimeException();

        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);

        $this->redis->on('error', $this->expectCallableNever());
        assert($client instanceof StreamingClient);
        $client->emit('error', [$error]);
    }

    public function testEmitsNoCloseEventWhenUnderlyingClientEmitsClose(): void
    {
        $client = $this->createMock(StreamingClient::class);
        $client->expects($this->once())->method('__call')->willReturn(\React\Promise\resolve(null));

        $deferred = new Deferred();
        $this->factory->expects($this->once())->method('createClient')->willReturn($deferred->promise());

        $this->redis->ping();
        $deferred->resolve($client);

        $this->redis->on('close', $this->expectCallableNever());
        assert($client instanceof StreamingClient);
        $client->emit('close');
    }

    public function testEmitsNoCloseEventButWillCancelIdleTimerWhenUnderlyingConnectionEmitsCloseAfterPingIsAlreadyResolved(): void
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

    public function testEmitsMessageEventWhenUnderlyingClientEmitsMessageForPubSubChannel(): void
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

    public function testEmitsUnsubscribeAndPunsubscribeEventsWhenUnderlyingClientClosesWhileUsingPubSubChannel(): void
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
        assert(isset($allHandler['subscribe']) && is_callable($allHandler['subscribe']));
        $allHandler['subscribe']('foo', 1);

        $this->redis->subscribe('bar');
        $allHandler['subscribe']('bar', 2);

        $this->redis->unsubscribe('bar');
        assert(isset($allHandler['unsubscribe']) && is_callable($allHandler['unsubscribe']));
        $allHandler['unsubscribe']('bar', 1);

        $this->redis->psubscribe('foo*');
        assert(isset($allHandler['psubscribe']) && is_callable($allHandler['psubscribe']));
        $allHandler['psubscribe']('foo*', 1);

        $this->redis->psubscribe('bar*');
        $allHandler['psubscribe']('bar*', 2);

        $this->redis->punsubscribe('bar*');
        assert(isset($allHandler['punsubscribe']) && is_callable($allHandler['punsubscribe']));
        $allHandler['punsubscribe']('bar*', 1);

        $this->redis->on('unsubscribe', $this->expectCallableOnce());
        $this->redis->on('punsubscribe', $this->expectCallableOnce());

        $this->assertTrue(is_callable($allHandler['close']));
        $allHandler['close']();
    }

    public function testSubscribeWillResolveWhenUnderlyingClientResolvesSubscribeAndNotStartIdleTimerWithIdleDueToSubscription(): void
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

    public function testUnsubscribeAfterSubscribeWillResolveWhenUnderlyingClientResolvesUnsubscribeAndStartIdleTimerWhenSubscriptionStopped(): void
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

    public function testBlpopWillRejectWhenUnderlyingClientClosesWhileWaitingForResponse(): void
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
