<?php

use Clue\React\Block;
use Clue\React\Redis\Factory;
use Clue\React\Redis\StreamingClient;
use React\Promise\Deferred;
use React\Stream\Stream;
use React\Stream\DuplexResourceStream;

class FunctionalTest extends TestCase
{
    private $loop;
    private $factory;
    private $client;

    public function setUp()
    {
        $uri = getenv('REDIS_URI');
        if ($uri === false) {
            $this->markTestSkipped('No REDIS_URI environment variable given');
        }

        $this->loop = new React\EventLoop\StreamSelectLoop();
        $this->factory = new Factory($this->loop);
        $this->client = $this->createClient($uri);
    }

    public function testPing()
    {
        $client = $this->client;

        $promise = $client->ping();
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $ret = Block\await($promise, $this->loop);

        $this->assertEquals('PONG', $ret);

        return $client;
    }

    public function testMgetIsNotInterpretedAsSubMessage()
    {
        $client = $this->client;

        $client->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        $promise = $client->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $client->on('message', $this->expectCallableNever());

        Block\await($promise, $this->loop);
    }

    public function testPipeline()
    {
        $client = $this->client;

        $client->set('a', 1)->then($this->expectCallableOnce('OK'));
        $client->incr('a')->then($this->expectCallableOnce(2));
        $client->incr('a')->then($this->expectCallableOnce(3));
        $promise = $client->get('a')->then($this->expectCallableOnce('3'));

        Block\await($promise, $this->loop);
    }

    public function testInvalidCommand()
    {
        $promise = $this->client->doesnotexist(1, 2, 3);

        $this->setExpectedException('Exception');
        Block\await($promise, $this->loop);
    }

    public function testMultiExecEmpty()
    {
        $this->client->multi()->then($this->expectCallableOnce('OK'));
        $promise = $this->client->exec()->then($this->expectCallableOnce(array()));

        Block\await($promise, $this->loop);
    }

    public function testMultiExecQueuedExecHasValues()
    {
        $client = $this->client;

        $client->multi()->then($this->expectCallableOnce('OK'));
        $client->set('b', 10)->then($this->expectCallableOnce('QUEUED'));
        $client->expire('b', 20)->then($this->expectCallableOnce('QUEUED'));
        $client->incrBy('b', 2)->then($this->expectCallableOnce('QUEUED'));
        $client->ttl('b')->then($this->expectCallableOnce('QUEUED'));
        $promise = $client->exec()->then($this->expectCallableOnce(array('OK', 1, 12, 20)));

        Block\await($promise, $this->loop);
    }

    public function testPubSub()
    {
        $consumer = $this->client;
        $producer = $this->createClient(getenv('REDIS_URI'));

        $channel = 'channel:test:' . mt_rand();

        // consumer receives a single message
        $deferred = new Deferred();
        $consumer->on('message', $this->expectCallableOnce());
        $consumer->on('message', array($deferred, 'resolve'));
        $consumer->subscribe($channel)->then($this->expectCallableOnce());

        // producer sends a single message
        $producer->publish($channel, 'hello world')->then($this->expectCallableOnce(1));

        // expect "message" event to take no longer than 0.1s
        Block\await($deferred->promise(), $this->loop, 0.1);
    }

    public function testClose()
    {
        $this->client->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $this->client->close();

        $this->client->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testInvalidProtocol()
    {
        $client = $this->createClientResponse("communication does not conform to protocol\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $promise = $client->get('willBeRejectedDueToClosing');

        $this->setExpectedException('Exception');
        Block\await($promise, $this->loop);
    }

    public function testInvalidServerRepliesWithDuplicateMessages()
    {
        $client = $this->createClientResponse("+OK\r\n-ERR invalid\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $promise = $client->set('a', 0)->then($this->expectCallableOnce('OK'));

        Block\await($promise, $this->loop);
    }

    /**
     * @param string $uri
     * @return Client
     */
    protected function createClient($uri)
    {
        return Block\await($this->factory->createClient($uri), $this->loop);
    }

    protected function createClientResponse($response)
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $response);
        fseek($fp, 0);

        $stream = class_exists('React\Stream\DuplexResourceStream') ? new DuplexResourceStream($fp, $this->loop) : new Stream($fp, $this->loop);

        return new StreamingClient($stream);
    }

    protected function createServer($response)
    {
        $port = 1337;
        $cmd = 'echo -e "' . str_replace("\r\n", '\r\n', $response) . '" | nc -lC ' . $port;

    }
}
