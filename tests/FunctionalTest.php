<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\RedisClient;
use React\EventLoop\Loop;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use function React\Async\await;

class FunctionalTest extends TestCase
{

    /** @var string */
    private $uri;

    public function setUp(): void
    {
        $this->uri = getenv('REDIS_URI') ?: '';
        if ($this->uri === '') {
            $this->markTestSkipped('No REDIS_URI environment variable given');
        }
    }

    public function testPing(): void
    {
        $redis = new RedisClient($this->uri);

        /** @var PromiseInterface<string> */
        $promise = $redis->ping();

        $ret = await($promise);

        $this->assertEquals('PONG', $ret);
    }

    public function testPingLazy(): void
    {
        $redis = new RedisClient($this->uri);

        /** @var PromiseInterface<string> */
        $promise = $redis->ping();

        $ret = await($promise);

        $this->assertEquals('PONG', $ret);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPingLazyWillNotBlockLoop(): void
    {
        $redis = new RedisClient($this->uri);

        $redis->ping();

        Loop::run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testLazyClientWithoutCommandsWillNotBlockLoop(): void
    {
        $redis = new RedisClient($this->uri);

        Loop::run();

        unset($redis);
    }

    public function testMgetIsNotInterpretedAsSubMessage(): void
    {
        $redis = new RedisClient($this->uri);

        $redis->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        /** @var PromiseInterface<never> */
        $promise = $redis->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $redis->on('message', $this->expectCallableNever());

        await($promise);
    }

    public function testPipeline(): void
    {
        $redis = new RedisClient($this->uri);

        $redis->set('a', 1)->then($this->expectCallableOnceWith('OK'));
        $redis->incr('a')->then($this->expectCallableOnceWith(2));
        $redis->incr('a')->then($this->expectCallableOnceWith(3));

        /** @var PromiseInterface<void> */
        $promise = $redis->get('a')->then($this->expectCallableOnceWith('3'));

        await($promise);
    }

    public function testInvalidCommand(): void
    {
        $redis = new RedisClient($this->uri);

        /** @var PromiseInterface<never> */
        $promise = $redis->doesnotexist(1, 2, 3);

        $this->expectException(\Exception::class);
        await($promise);
    }

    public function testMultiExecEmpty(): void
    {
        $redis = new RedisClient($this->uri);
        $redis->multi()->then($this->expectCallableOnceWith('OK'));

        /** @var PromiseInterface<void> */
        $promise = $redis->exec()->then($this->expectCallableOnceWith([]));

        await($promise);
    }

    public function testMultiExecQueuedExecHasValues(): void
    {
        $redis = new RedisClient($this->uri);

        $redis->multi()->then($this->expectCallableOnceWith('OK'));
        $redis->set('b', 10)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->expire('b', 20)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->incrBy('b', 2)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->ttl('b')->then($this->expectCallableOnceWith('QUEUED'));

        /** @var PromiseInterface<void> */
        $promise = $redis->exec()->then($this->expectCallableOnceWith(['OK', 1, 12, 20]));

        await($promise);
    }

    public function testPubSub(): void
    {
        $consumer = new RedisClient($this->uri);
        $producer = new RedisClient($this->uri);

        $channel = 'channel:test:' . mt_rand();

        // consumer receives a single message
        $consumer->on('message', $this->expectCallableOnce());
        $once = $this->expectCallableOnceWith(1);
        $consumer->subscribe($channel)->then(function() use ($producer, $channel, $once){
            // producer sends a single message
            $producer->publish($channel, 'hello world')->then($once);
        })->then($this->expectCallableOnce());

        // expect "message" event to take no longer than 0.1s
        await(new Promise(function (callable $resolve, callable $reject) use ($consumer): void {
            $timeout = Loop::addTimer(0.1, function () use ($consumer, $reject): void {
                $consumer->close();
                $reject(new \RuntimeException('Timed out'));
            });
            $consumer->on('message', function () use ($timeout, $resolve): void {
                Loop::cancelTimer($timeout);
                $resolve(null);
            });
        }));

        /** @var PromiseInterface<array{0:"unsubscribe",1:string,2:0}> */
        $promise = $consumer->unsubscribe($channel);
        await($promise);
    }

    public function testClose(): void
    {
        $redis = new RedisClient($this->uri);

        $redis->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $redis->close();

        $redis->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }
}
