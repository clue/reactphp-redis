<?php

use Clue\React\Redis\ResponseApi;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\React\Redis\Client;

class ResponseApiTest extends TestCase
{
    private $stream;
    private $client;
    private $responseApi;

    public function setUp()
    {
        //$this->stream = $this->getMock('React\Stream\Stream');
        //$this->client = new Client($this->stream);
        $this->client = $this->getMockBuilder('Clue\React\Redis\Client')->disableOriginalConstructor()->setMethods(array('sendRequest', 'close'))->getMock();
        $this->responseApi = new ResponseApi($this->client);
    }

    public function testPingPong()
    {
        $this->client->expects($this->once())->method('sendRequest')->with($this->equalTo('ping'));

        $promise = $this->responseApi->ping();

        $this->client->emit('message', array(new BulkReply('PONG')));

        $this->expectPromiseResolve($promise);
        $promise->then($this->expectCallableOnce('PONG'));
    }

    public function testErrorReply()
    {
        $promise = $this->responseApi->invalid();

        $err = new ErrorReply('ERR invalid command');
        $this->client->emit('message', array($err));

        $this->expectPromiseReject($promise);
        $promise->then(null, $this->expectCallableOnce($err));
    }

    public function testClosingClientRejectsAllRemainingRequests()
    {
        $promise = $this->responseApi->ping();
        $this->assertTrue($this->responseApi->isBusy());

        $this->client->emit('close');

        $this->expectPromiseReject($promise);
        $this->assertFalse($this->responseApi->isBusy());
    }

    public function testClosedClientRejectsAllNewRequests()
    {
        $promise = $this->responseApi->ping();

        $this->client->emit('close');

        $this->expectPromiseReject($promise);
        $this->assertFalse($this->responseApi->isBusy());
    }

    public function testEndingNonBusyClosesClient()
    {
        $this->client->expects($this->once())->method('close');
        $this->responseApi->end();
    }

    public function testEndingBusyClosesClientWhenNotBusyAnymore()
    {
        // count how often the "close" method has been called
        $closed = 0;
        $this->client->method('close')->will($this->returnCallback(function() use (&$closed) {
            ++$closed;
        }));

        $promise = $this->responseApi->ping();
        $this->assertEquals(0, $closed);

        $this->responseApi->end();
        $this->assertEquals(0, $closed);

        $this->client->emit('message', array(new BulkReply('PONG')));
        $this->assertEquals(1, $closed);
    }
}
