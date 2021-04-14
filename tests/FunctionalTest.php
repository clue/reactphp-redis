<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Block;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Clue\React\Redis\StreamingClient;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Stream\DuplexResourceStream;

class FunctionalTest extends TestCase
{
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

        Loop::set(new StreamSelectLoop());
        $this->factory = new Factory();
    }


    /**
     * @after
     */
    public function resetLoop()
    {
        Loop::reset();
    }

    public function testPing()
    {
        $client = $this->createClient($this->uri);

        $promise = $client->ping();
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('PONG', $ret);
    }

    public function testPingLazy()
    {
        $client = $this->factory->createLazyClient($this->uri);

        $promise = $client->ping();
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $ret = Block\await($promise, Loop::get());

        $this->assertEquals('PONG', $ret);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPingLazyWillNotBlockLoopWhenIdleTimeIsSmall()
    {
        $client = $this->factory->createLazyClient($this->uri . '?idle=0');

        $client->ping();

        Loop::get()->run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testLazyClientWithoutCommandsWillNotBlockLoop()
    {
        $client = $this->factory->createLazyClient($this->uri);

        Loop::get()->run();

        unset($client);
    }

    public function testMgetIsNotInterpretedAsSubMessage()
    {
        $client = $this->createClient($this->uri);

        $client->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        $promise = $client->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $client->on('message', $this->expectCallableNever());

        Block\await($promise, Loop::get());
    }

    public function testPipeline()
    {
        $client = $this->createClient($this->uri);

        $client->set('a', 1)->then($this->expectCallableOnceWith('OK'));
        $client->incr('a')->then($this->expectCallableOnceWith(2));
        $client->incr('a')->then($this->expectCallableOnceWith(3));
        $promise = $client->get('a')->then($this->expectCallableOnceWith('3'));

        Block\await($promise, Loop::get());
    }

    public function testInvalidCommand()
    {
        $client = $this->createClient($this->uri);
        $promise = $client->doesnotexist(1, 2, 3);

        if (method_exists($this, 'expectException')) {
            $this->expectException('Exception');
        } else {
            $this->setExpectedException('Exception');
        }
        Block\await($promise, Loop::get());
    }

    public function testMultiExecEmpty()
    {
        $client = $this->createClient($this->uri);
        $client->multi()->then($this->expectCallableOnceWith('OK'));
        $promise = $client->exec()->then($this->expectCallableOnceWith(array()));

        Block\await($promise, Loop::get());
    }

    public function testMultiExecQueuedExecHasValues()
    {
        $client = $this->createClient($this->uri);

        $client->multi()->then($this->expectCallableOnceWith('OK'));
        $client->set('b', 10)->then($this->expectCallableOnceWith('QUEUED'));
        $client->expire('b', 20)->then($this->expectCallableOnceWith('QUEUED'));
        $client->incrBy('b', 2)->then($this->expectCallableOnceWith('QUEUED'));
        $client->ttl('b')->then($this->expectCallableOnceWith('QUEUED'));
        $promise = $client->exec()->then($this->expectCallableOnceWith(array('OK', 1, 12, 20)));

        Block\await($promise, Loop::get());
    }

    public function testPubSub()
    {
        $consumer = $this->createClient($this->uri);
        $producer = $this->createClient($this->uri);

        $channel = 'channel:test:' . mt_rand();

        // consumer receives a single message
        $deferred = new Deferred();
        $consumer->on('message', $this->expectCallableOnce());
        $consumer->on('message', array($deferred, 'resolve'));
        $once = $this->expectCallableOnceWith(1);
        $consumer->subscribe($channel)->then(function() use ($producer, $channel, $once){
            // producer sends a single message
            $producer->publish($channel, 'hello world')->then($once);
        })->then($this->expectCallableOnce());

        // expect "message" event to take no longer than 0.1s
        Block\await($deferred->promise(), Loop::get(), 0.1);
    }

    public function testClose()
    {
        $client = $this->createClient($this->uri);

        $client->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $client->close();

        $client->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testCloseLazy()
    {
        $client = $this->factory->createLazyClient($this->uri);

        $client->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $client->close();

        $client->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testInvalidProtocol()
    {
        $client = $this->createClientResponse("communication does not conform to protocol\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $promise = $client->get('willBeRejectedDueToClosing');

        if (method_exists($this, 'expectException')) {
            $this->expectException('Exception');
        } else {
            $this->setExpectedException('Exception');
        }
        Block\await($promise, Loop::get());
    }

    public function testInvalidServerRepliesWithDuplicateMessages()
    {
        $client = $this->createClientResponse("+OK\r\n-ERR invalid\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $promise = $client->set('a', 0)->then($this->expectCallableOnceWith('OK'));

        Block\await($promise, Loop::get());
    }

    /**
     * @param string $uri
     * @return Client
     */
    protected function createClient($uri)
    {
        return Block\await($this->factory->createClient($uri), Loop::get());
    }

    protected function createClientResponse($response)
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $response);
        fseek($fp, 0);

        $stream = new DuplexResourceStream($fp, Loop::get());

        return new StreamingClient($stream);
    }
}
