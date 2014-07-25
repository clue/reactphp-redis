<?php

use Clue\React\Redis\Client;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\IntegerReply;

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

        $this->client = new Client($this->stream, $this->parser, $this->serializer);
    }

    public function testSending()
    {
        $this->serializer->expects($this->once())->method('getRequestMessage')->with($this->equalTo('ping'))->will($this->returnValue('message'));
        $this->stream->expects($this->once())->method('write')->with($this->equalTo('message'));

        $this->client->send('ping', array());
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
        $this->client->on('message', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->returnValue(array(new IntegerReply(2))));
        $this->stream->emit('data', array('message'));
    }

    public function testReceiveThrowMessageEmitsErrorEvent()
    {
        $this->client->on('message', $this->expectCallableOnce());
        $this->client->on('message', function() {
            throw new UnderflowException();
        });

        $this->client->on('error', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with($this->equalTo('message'))->will($this->returnValue(array(new IntegerReply(2))));
        $this->stream->emit('data', array('message'));
    }

    public function testDefaultCtor()
    {
        $client = new Client($this->stream);
    }
}
