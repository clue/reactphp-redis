<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\RedisClient;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class FunctionalTest extends TestCase
{
    /** @var StreamSelectLoop */
    private $loop;

    /** @var string */
    private $uri;

    public function setUp(): void
    {
        $this->uri = getenv('REDIS_URI') ?: '';
        if ($this->uri === '') {
            $this->markTestSkipped('No REDIS_URI environment variable given');
        }

        $this->loop = new StreamSelectLoop();
    }

    public function testPing()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $promise = $redis->ping();
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $ret = \React\Async\await($promise);

        $this->assertEquals('PONG', $ret);
    }

    public function testPingLazy()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $promise = $redis->ping();
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $ret = \React\Async\await($promise);

        $this->assertEquals('PONG', $ret);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPingLazyWillNotBlockLoop()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->ping();

        $this->loop->run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testLazyClientWithoutCommandsWillNotBlockLoop()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $this->loop->run();

        unset($redis);
    }

    public function testMgetIsNotInterpretedAsSubMessage()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        $promise = $redis->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $redis->on('message', $this->expectCallableNever());

        \React\Async\await($promise);
    }

    public function testPipeline()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->set('a', 1)->then($this->expectCallableOnceWith('OK'));
        $redis->incr('a')->then($this->expectCallableOnceWith(2));
        $redis->incr('a')->then($this->expectCallableOnceWith(3));
        $promise = $redis->get('a')->then($this->expectCallableOnceWith('3'));

        \React\Async\await($promise);
    }

    public function testInvalidCommand()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);
        $promise = $redis->doesnotexist(1, 2, 3);

        if (method_exists($this, 'expectException')) {
            $this->expectException('Exception');
        } else {
            $this->setExpectedException('Exception');
        }
        \React\Async\await($promise);
    }

    public function testMultiExecEmpty()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);
        $redis->multi()->then($this->expectCallableOnceWith('OK'));
        $promise = $redis->exec()->then($this->expectCallableOnceWith([]));

        \React\Async\await($promise);
    }

    public function testMultiExecQueuedExecHasValues()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->multi()->then($this->expectCallableOnceWith('OK'));
        $redis->set('b', 10)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->expire('b', 20)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->incrBy('b', 2)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->ttl('b')->then($this->expectCallableOnceWith('QUEUED'));
        $promise = $redis->exec()->then($this->expectCallableOnceWith(['OK', 1, 12, 20]));

        \React\Async\await($promise);
    }

    public function testPubSub()
    {
        $consumer = new RedisClient($this->uri, null, $this->loop);
        $producer = new RedisClient($this->uri, null, $this->loop);

        $channel = 'channel:test:' . mt_rand();

        // consumer receives a single message
        $deferred = new Deferred();
        $consumer->on('message', $this->expectCallableOnce());
        $consumer->on('message', [$deferred, 'resolve']);
        $once = $this->expectCallableOnceWith(1);
        $consumer->subscribe($channel)->then(function() use ($producer, $channel, $once){
            // producer sends a single message
            $producer->publish($channel, 'hello world')->then($once);
        })->then($this->expectCallableOnce());

        // expect "message" event to take no longer than 0.1s
        \React\Async\await($deferred->promise());
    }

    public function testClose()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $redis->close();

        $redis->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testCloseLazy()
    {
        $redis = new RedisClient($this->uri, null, $this->loop);

        $redis->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $redis->close();

        $redis->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }
}
