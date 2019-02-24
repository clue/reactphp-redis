<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\StreamingClient;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\React\Redis\Client;
use React\Stream\ThroughStream;

class StreamingClientTest extends TestCase
{
    private $stream;
    private $parser;
    private $serializer;
    private $client;

    public function setUp()
    {
        $this->stream = $this->getMockBuilder('React\Stream\DuplexStreamInterface')->getMock();
        $this->parser = $this->getMockBuilder('Clue\Redis\Protocol\Parser\ParserInterface')->getMock();
        $this->serializer = $this->getMockBuilder('Clue\Redis\Protocol\Serializer\SerializerInterface')->getMock();

        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);
    }

    public function testSending()
    {
        $this->serializer->expects($this->once())->method('getRequestMessage')->with($this->equalTo('ping'))->will($this->returnValue('message'));
        $this->stream->expects($this->once())->method('write')->with($this->equalTo('message'));

        $this->client->ping();
    }

    public function testClosingClientEmitsEvent()
    {
        $this->client->on('close', $this->expectCallableOnce());

        $this->client->close();
    }

    public function testClosingStreamClosesClient()
    {
        $this->stream = new ThroughStream();
        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);

        $this->client->on('close', $this->expectCallableOnce());

        $this->stream->emit('close');
    }

    public function testReceiveParseErrorEmitsErrorEvent()
    {
        $this->stream = new ThroughStream();
        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);

        $this->client->on('error', $this->expectCallableOnce());
        $this->client->on('close', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->throwException(new ParserException()));
        $this->stream->emit('data', array('message'));
    }

    public function testReceiveThrowMessageEmitsErrorEvent()
    {
        $this->stream = new ThroughStream();
        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);

        $this->client->on('error', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->returnValue(array(new IntegerReply(2))));
        $this->stream->emit('data', array('message'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDefaultCtor()
    {
        $client = new StreamingClient($this->stream);
    }

    public function testPingPong()
    {
        $this->serializer->expects($this->once())->method('getRequestMessage')->with($this->equalTo('ping'));

        $promise = $this->client->ping();

        $this->client->handleMessage(new BulkReply('PONG'));

        $this->expectPromiseResolve($promise);
        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testMonitorCommandIsNotSupported()
    {
        $promise = $this->client->monitor();

        $this->expectPromiseReject($promise);
    }


    public function testErrorReply()
    {
        $promise = $this->client->invalid();

        $err = new ErrorReply("ERR unknown command 'invalid'");
        $this->client->handleMessage($err);

        $this->expectPromiseReject($promise);
        $promise->then(null, $this->expectCallableOnceWith($err));
    }

    public function testClosingClientRejectsAllRemainingRequests()
    {
        $promise = $this->client->ping();
        $this->client->close();

        $this->expectPromiseReject($promise);
    }

    public function testClosedClientRejectsAllNewRequests()
    {
        $this->client->close();
        $promise = $this->client->ping();

        $this->expectPromiseReject($promise);
    }

    public function testEndingNonBusyClosesClient()
    {
        $this->client->on('close', $this->expectCallableOnce());
        $this->client->end();
    }

    public function testEndingBusyClosesClientWhenNotBusyAnymore()
    {
        // count how often the "close" method has been called
        $closed = 0;
        $this->client->on('close', function() use (&$closed) {
            ++$closed;
        });

        $promise = $this->client->ping();
        $this->assertEquals(0, $closed);

        $this->client->end();
        $this->assertEquals(0, $closed);

        $this->client->handleMessage(new BulkReply('PONG'));
        $promise->then($this->expectCallableOnceWith('PONG'));
        $this->assertEquals(1, $closed);
    }

    public function testClosingMultipleTimesEmitsOnce()
    {
        $this->client->on('close', $this->expectCallableOnce());

        $this->client->close();
        $this->client->close();
    }

    public function testReceivingUnexpectedMessageThrowsException()
    {
        if (method_exists($this, 'expectException')) {
            $this->expectException('UnderflowException');
        } else {
            $this->setExpectedException('UnderflowException');
        }
        $this->client->handleMessage(new BulkReply('PONG'));
    }

    public function testPubsubSubscribe()
    {
        $promise = $this->client->subscribe('test');
        $this->expectPromiseResolve($promise);

        $this->client->on('subscribe', $this->expectCallableOnce());
        $this->client->handleMessage(new MultiBulkReply(array(new BulkReply('subscribe'), new BulkReply('test'), new IntegerReply(1))));

        return $this->client;
    }

    /**
     * @depends testPubsubSubscribe
     * @param Client $client
     */
    public function testPubsubPatternSubscribe(Client $client)
    {
         $promise = $client->psubscribe('demo_*');
         $this->expectPromiseResolve($promise);

         $client->on('psubscribe', $this->expectCallableOnce());
         $client->handleMessage(new MultiBulkReply(array(new BulkReply('psubscribe'), new BulkReply('demo_*'), new IntegerReply(1))));

        return $client;
    }

    /**
     * @depends testPubsubPatternSubscribe
     * @param Client $client
     */
    public function testPubsubMessage(Client $client)
    {
        $client->on('message', $this->expectCallableOnce());
        $client->handleMessage(new MultiBulkReply(array(new BulkReply('message'), new BulkReply('test'), new BulkReply('payload'))));
    }

    public function testPubsubSubscribeSingleOnly()
    {
        $this->expectPromiseReject($this->client->subscribe('a', 'b'));
        $this->expectPromiseReject($this->client->unsubscribe('a', 'b'));
        $this->expectPromiseReject($this->client->unsubscribe());
    }
}
