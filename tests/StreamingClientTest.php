<?php

namespace Clue\Tests\React\Redis;

use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\React\Redis\Client;
use Clue\React\Redis\StreamingClient;
use React\Stream\ThroughStream;

class StreamingClientTest extends TestCase
{
    private $stream;
    private $parser;
    private $serializer;
    private $client;

    /**
     * @before
     */
    public function setUpClient()
    {
        $this->stream = $this->getMockBuilder('React\Stream\DuplexStreamInterface')->getMock();
        $this->parser = $this->getMockBuilder('Clue\Redis\Protocol\Parser\ParserInterface')->getMock();
        $this->serializer = $this->getMockBuilder('Clue\Redis\Protocol\Serializer\SerializerInterface')->getMock();

        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);
    }

    public function testConstructWithoutParserAssignsParserAutomatically()
    {
        $this->client = new StreamingClient($this->stream, null, $this->serializer);

        $ref = new \ReflectionProperty($this->client, 'parser');
        $ref->setAccessible(true);
        $parser = $ref->getValue($this->client);

        $this->assertInstanceOf('Clue\Redis\Protocol\Parser\ParserInterface', $parser);
    }

    public function testConstructWithoutParserAndSerializerAssignsParserAndSerializerAutomatically()
    {
        $this->client = new StreamingClient($this->stream, $this->parser);

        $ref = new \ReflectionProperty($this->client, 'serializer');
        $ref->setAccessible(true);
        $serializer = $ref->getValue($this->client);

        $this->assertInstanceOf('Clue\Redis\Protocol\Serializer\SerializerInterface', $serializer);
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

        $this->client->on('error', $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('UnexpectedValueException'),
                $this->callback(function (\UnexpectedValueException $e) {
                    return $e->getMessage() === 'Invalid data received: Foo (EBADMSG)';
                }),
                $this->callback(function (\UnexpectedValueException $e) {
                    return $e->getCode() === (defined('SOCKET_EBADMSG') ? SOCKET_EBADMSG : 77);
                })
            )
        ));
        $this->client->on('close', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with('message')->willThrowException(new ParserException('Foo'));
        $this->stream->emit('data', array('message'));
    }

    public function testReceiveUnexpectedReplyEmitsErrorEvent()
    {
        $this->stream = new ThroughStream();
        $this->client = new StreamingClient($this->stream, $this->parser, $this->serializer);

        $this->client->on('error', $this->expectCallableOnce());
        $this->client->on('error', $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('UnderflowException'),
                $this->callback(function (\UnderflowException $e) {
                    return $e->getMessage() === 'Unexpected reply received, no matching request found (ENOMSG)';
                }),
                $this->callback(function (\UnderflowException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOMSG') ? SOCKET_ENOMSG : 42);
                })
            )
        ));


        $this->parser->expects($this->once())->method('pushIncoming')->with('message')->willReturn(array(new IntegerReply(2)));
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

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('BadMethodCallException'),
                $this->callback(function (\BadMethodCallException $e) {
                    return $e->getMessage() === 'MONITOR command explicitly not supported (ENOTSUP)';
                }),
                $this->callback(function (\BadMethodCallException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTSUP') ? SOCKET_ENOTSUP : (defined('SOCKET_EOPNOTSUPP') ? SOCKET_EOPNOTSUPP : 95));
                })
            )
        ));
    }

    public function testErrorReply()
    {
        $promise = $this->client->invalid();

        $err = new ErrorReply("ERR unknown command 'invalid'");
        $this->client->handleMessage($err);

        $promise->then(null, $this->expectCallableOnceWith($err));
    }

    public function testClosingClientRejectsAllRemainingRequests()
    {
        $promise = $this->client->ping();
        $this->client->close();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testEndingClientRejectsAllNewRequests()
    {
        $this->client->ping();
        $this->client->end();
        $promise = $this->client->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testClosedClientRejectsAllNewRequests()
    {
        $this->client->close();
        $promise = $this->client->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closed (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
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

    public function testSubscribeWithMultipleArgumentsRejects()
    {
        $promise = $this->client->subscribe('a', 'b');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('InvalidArgumentException'),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'PubSub commands limited to single argument (EINVAL)';
                }),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getCode() === (defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22);
                })
            )
        ));
    }

    public function testUnsubscribeWithoutArgumentsRejects()
    {
        $promise = $this->client->unsubscribe();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('InvalidArgumentException'),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'PubSub commands limited to single argument (EINVAL)';
                }),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getCode() === (defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22);
                })
            )
        ));
    }
}
