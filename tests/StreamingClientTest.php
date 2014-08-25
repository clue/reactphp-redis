<?php

use Clue\React\Redis\StreamingClient;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;

class ClientTest extends TestCase
{
    private $stream;
    private $parser;
    private $serializer;
    private $client;

    public function setUp()
    {
        $this->stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->setMethods(array('write', 'close', 'resume', 'pause'))->getMock();
        $this->parser = $this->getMock('Clue\Redis\Protocol\Parser\ParserInterface');
        $this->serializer = $this->getMock('Clue\Redis\Protocol\Serializer\SerializerInterface');

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
        //$this->client->on('close', $this->expectCallableOnce());

        $this->client->close();
    }

    public function testClosingStreamClosesClient()
    {
        $this->client->on('close', $this->expectCallableOnce());

        $this->stream->emit('close');
    }

    public function testReceiveParseErrorEmitsErrorEvent()
    {
        $this->client->on('error', $this->expectCallableOnce());
        //$this->client->on('close', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->throwException(new ParserException()));
        $this->stream->emit('data', array('message'));
    }

    public function testReceiveMessageEmitsEvent()
    {
        $this->client->on('data', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->returnValue(array(new IntegerReply(2))));
        $this->stream->emit('data', array('message'));
    }

    public function testReceiveThrowMessageEmitsErrorEvent()
    {
        $this->client->on('data', $this->expectCallableOnce());
        $this->client->on('data', function() {
            throw new UnderflowException();
        });

        $this->client->on('error', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->returnValue(array(new IntegerReply(2))));
        $this->stream->emit('data', array('message'));
    }

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
        $promise->then($this->expectCallableOnce('PONG'));
    }

    public function testErrorReply()
    {
        $promise = $this->client->invalid();

        $err = new ErrorReply("ERR unknown command 'invalid'");
        $this->client->handleMessage($err);

        $this->expectPromiseReject($promise);
        $promise->then(null, $this->expectCallableOnce($err));
    }

    public function testClosingClientRejectsAllRemainingRequests()
    {
        $promise = $this->client->ping();
        $this->assertTrue($this->client->isBusy());

        $this->client->close();

        $this->expectPromiseReject($promise);
        $this->assertFalse($this->client->isBusy());
    }

    public function testClosedClientRejectsAllNewRequests()
    {
        $this->client->close();

        $promise = $this->client->ping();

        $this->expectPromiseReject($promise);
        $this->assertFalse($this->client->isBusy());
    }

    public function testEndingNonBusyClosesClient()
    {
        $this->assertFalse($this->client->isBusy());

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
        $promise->then($this->expectCallableOnce('PONG'));
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
        $this->setExpectedException('UnderflowException');
        $this->client->handleMessage(new BulkReply('PONG'));
    }
}
