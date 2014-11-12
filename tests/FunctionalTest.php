<?php

use React\Stream\Stream;

use React\Stream\ReadableStream;

use Clue\React\Redis\Factory;

use Clue\React\Redis\StreamingClient;

class FunctionalTest extends TestCase
{
    protected static $loop;
    protected static $factory;

    public static function setUpBeforeClass()
    {
        self::$loop = new React\EventLoop\StreamSelectLoop();
        self::$factory = new Factory(self::$loop);
    }

    public function testPing()
    {
        $client = $this->createClient();

        $promise = $client->ping();
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then($this->expectCallableOnce('PONG'));

        $this->assertTrue($client->isBusy());
        $this->waitFor($client);
        $this->assertFalse($client->isBusy());

        return $client;
    }

    public function testMgetIsNotInterpretedAsSubMessage()
    {
        $this->markTestIncomplete();

        $client = $this->createClient();

        $client->mset('message', 'message', 'channel', 'channel', 'payload', 'payload');

        $client->mget('message', 'channel', 'payload')->then($this->expectCallableOnce());
        $client->on('message', $this->expectCallableNever());

        $this->waitFor($client);
    }

    /**
     *
     * @param StreamingClient $client
     * @depends testPing
     */
    public function testPipeline(StreamingClient $client)
    {
        $this->assertFalse($client->isBusy());

        $client->set('a', 1)->then($this->expectCallableOnce('OK'));
        $client->incr('a')->then($this->expectCallableOnce(2));
        $client->incr('a')->then($this->expectCallableOnce(3));
        $client->get('a')->then($this->expectCallableOnce('3'));

        $this->assertTrue($client->isBusy());

        $this->waitFor($client);

        return $client;
    }

    /**
     *
     * @param StreamingClient $client
     * @depends testPipeline
     */
    public function testInvalidCommand(StreamingClient $client)
    {
        $client->doesnotexist(1, 2, 3)->then($this->expectCallableNever());

        $this->waitFor($client);

        return $client;
    }

    /**
     *
     * @param StreamingClient $client
     * @depends testInvalidCommand
     */
    public function testMultiExecEmpty(StreamingClient $client)
    {
        $client->multi()->then($this->expectCallableOnce('OK'));
        $client->exec()->then($this->expectCallableOnce(array()));

        $this->waitFor($client);

        return $client;
    }

    /**
     *
     * @param StreamingClient $client
     * @depends testMultiExecEmpty
     */
    public function testMultiExecQueuedExecHasValues(StreamingClient $client)
    {
        $client->multi()->then($this->expectCallableOnce('OK'));
        $client->set('b', 10)->then($this->expectCallableOnce('QUEUED'));
        $client->expire('b', 20)->then($this->expectCallableOnce('QUEUED'));
        $client->incrBy('b', 2)->then($this->expectCallableOnce('QUEUED'));
        $client->ttl('b')->then($this->expectCallableOnce('QUEUED'));
        $client->exec()->then($this->expectCallableOnce(array('OK', 1, 12, 20)));

        $this->waitFor($client);

        return $client;
    }

    /**
     *
     * @param StreamingClient $client
     * @depends testPipeline
     */
    public function testMonitorPing(StreamingClient $client)
    {
        $client->on('monitor', $this->expectCallableOnce());

        $client->monitor()->then($this->expectCallableOnce('OK'));
        $client->ping()->then($this->expectCallableOnce('PONG'));

        $this->waitFor($client);
    }

    public function testPubSub()
    {
        $consumer = $this->createClient();
        $producer = $this->createClient();

        $that = $this;

        $producer->publish('channel:test', 'nobody sees this')->then($this->expectCallableOnce(0));

        $this->waitFor($producer);

        $consumer->subscribe('channel:test')->then(function () {
            // ?
        });
    }

    public function testClose()
    {
        $client = $this->createClient();

        $client->get('willBeCanceledAnyway')->then(null, $this->expectCallableOnce());

        $client->close();

        $client->get('willBeRejectedRightAway')->then(null, $this->expectCallableOnce());
    }

    public function testInvalidProtocol()
    {
        $client = $this->createClientResponse("communication does not conform to protocol\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $client->get('willBeRejectedDueToClosing')->then(null, $this->expectCallableOnce());

        $this->waitFor($client);
    }

    public function testInvalidServerRepliesWithDuplicateMessages()
    {
        $client = $this->createClientResponse("+OK\r\n-ERR invalid\r\n");

        $client->on('error', $this->expectCallableOnce());
        $client->on('close', $this->expectCallableOnce());

        $client->set('a', 0)->then($this->expectCallableOnce('OK'));

        $this->waitFor($client);
    }

    /**
     * @return Client
     */
    protected function createClient()
    {
        $client = null;
        $exception = null;

        self::$factory->createClient()->then(function ($c) use (&$client) {
            $client = $c;
        }, function($error) use (&$exception) {
            $exception = $error;
        });

        while ($client === null && $exception === null) {
            self::$loop->tick();
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $client;
    }

    protected function createClientResponse($response)
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $response);
        fseek($fp, 0);

        $stream = new Stream($fp, self::$loop);

        return new StreamingClient($stream);
    }

    protected function createServer($response)
    {
        $port = 1337;
        $cmd = 'echo -e "' . str_replace("\r\n", '\r\n', $response) . '" | nc -lC ' . $port;

    }

    protected function waitFor(StreamingClient $client)
    {
        $this->assertTrue($client->isBusy());

        while ($client->isBusy()) {
            self::$loop->tick();
        }
    }
}
