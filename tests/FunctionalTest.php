<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\React\Redis\StreamingClient;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\DuplexResourceStream;
use function Clue\React\Block\await;

class FunctionalTest extends TestCase
{
    private $loop;
    private $factory;
    private $uri;

    /**
     * @before
     */
    public function setUpFactory()
    {
        $this->uri = getenv('REDIS_URI');
        if ($this->uri === false) {
            $this->markTestSkipped('No REDIS_URI environment variable given');
        }

        $this->loop = new StreamSelectLoop();
        $this->factory = new Factory($this->loop);
    }

    public function testPing()
    {
        $redis = $this->createClient($this->uri);

        $promise = $redis->ping();
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $ret = await($promise, $this->loop);

        $this->assertEquals('PONG', $ret);
    }

    public function testPingLazy()
    {
        $redis = $this->factory->createLazyClient($this->uri);

        $promise = $redis->ping();
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $ret = await($promise, $this->loop);

        $this->assertEquals('PONG', $ret);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPingLazyWillNotBlockLoopWhenIdleTimeIsSmall()
    {
        $redis = $this->factory->createLazyClient($this->uri . '?idle=0');

        $redis->ping();

        $this->loop->run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testLazyClientWithoutCommandsWillNotBlockLoop()
    {
        $redis = $this->factory->createLazyClient($this->uri);

        $this->loop->run();

        unset($redis);
    }

    public function testMgetIsNotInterpretedAsSubMessage()
    {
        $redis = $this->createClient($this->uri);

        $redis->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        $promise = $redis->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $redis->on('message', $this->expectCallableNever());

        await($promise, $this->loop);
    }

    public function testPipeline()
    {
        $redis = $this->createClient($this->uri);

        $redis->set('a', 1)->then($this->expectCallableOnceWith('OK'));
        $redis->incr('a')->then($this->expectCallableOnceWith(2));
        $redis->incr('a')->then($this->expectCallableOnceWith(3));
        $promise = $redis->get('a')->then($this->expectCallableOnceWith('3'));

        await($promise, $this->loop);
    }

    public function testInvalidCommand()
    {
        $redis = $this->createClient($this->uri);
        $promise = $redis->doesnotexist(1, 2, 3);

        if (method_exists($this, 'expectException')) {
            $this->expectException('Exception');
        } else {
            $this->setExpectedException('Exception');
        }
        await($promise, $this->loop);
    }

    public function testMultiExecEmpty()
    {
        $redis = $this->createClient($this->uri);
        $redis->multi()->then($this->expectCallableOnceWith('OK'));
        $promise = $redis->exec()->then($this->expectCallableOnceWith([]));

        await($promise, $this->loop);
    }

    public function testMultiExecQueuedExecHasValues()
    {
        $redis = $this->createClient($this->uri);

        $redis->multi()->then($this->expectCallableOnceWith('OK'));
        $redis->set('b', 10)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->expire('b', 20)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->incrBy('b', 2)->then($this->expectCallableOnceWith('QUEUED'));
        $redis->ttl('b')->then($this->expectCallableOnceWith('QUEUED'));
        $promise = $redis->exec()->then($this->expectCallableOnceWith(['OK', 1, 12, 20]));

        await($promise, $this->loop);
    }

    public function testPubSub()
    {
        $consumer = $this->createClient($this->uri);
        $producer = $this->createClient($this->uri);

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
        await($deferred->promise(), $this->loop, 0.1);
    }

    public function testClose()
    {
        $redis = $this->createClient($this->uri);

        $redis->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $redis->close();

        $redis->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testCloseLazy()
    {
        $redis = $this->factory->createLazyClient($this->uri);

        $redis->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $redis->close();

        $redis->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testInvalidProtocol()
    {
        $redis = $this->createClientResponse("communication does not conform to protocol\r\n");

        $redis->on('error', $this->expectCallableOnce());
        $redis->on('close', $this->expectCallableOnce());

        $promise = $redis->get('willBeRejectedDueToClosing');

        $this->expectException(\Exception::class);
        await($promise, $this->loop);
    }

    public function testInvalidServerRepliesWithDuplicateMessages()
    {
        $redis = $this->createClientResponse("+OK\r\n-ERR invalid\r\n");

        $redis->on('error', $this->expectCallableOnce());
        $redis->on('close', $this->expectCallableOnce());

        $promise = $redis->set('a', 0)->then($this->expectCallableOnceWith('OK'));

        await($promise, $this->loop);
    }

    /**
     * @param string $uri
     * @return Client
     */
    protected function createClient($uri)
    {
        return await($this->factory->createClient($uri), $this->loop);
    }

    protected function createClientResponse($response)
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $response);
        fseek($fp, 0);

        $stream = new DuplexResourceStream($fp, $this->loop);

        return new StreamingClient($stream);
    }
}
