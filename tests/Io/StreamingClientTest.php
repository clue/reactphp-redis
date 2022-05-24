<?php

namespace Clue\Tests\React\Redis\Io;

use Clue\React\Redis\Io\StreamingClient;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\IntegerReply;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Clue\Tests\React\Redis\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use React\Stream\ThroughStream;
use React\Stream\DuplexStreamInterface;

class StreamingClientTest extends TestCase
{
    /** @var MockObject */
    private $stream;

    /** @var MockObject */
    private $parser;

    /** @var MockObject */
    private $serializer;

    /** @var StreamingClient */
    private $redis;

    public function setUp(): void
    {
        $this->stream = $this->createMock(DuplexStreamInterface::class);
        $this->parser = $this->createMock(ParserInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->redis = new StreamingClient($this->stream, $this->parser, $this->serializer);
    }

    public function testConstructWithoutParserAssignsParserAutomatically()
    {
        $this->redis = new StreamingClient($this->stream, null, $this->serializer);

        $ref = new \ReflectionProperty($this->redis, 'parser');
        $ref->setAccessible(true);
        $parser = $ref->getValue($this->redis);

        $this->assertInstanceOf(ParserInterface::class, $parser);
    }

    public function testConstructWithoutParserAndSerializerAssignsParserAndSerializerAutomatically()
    {
        $this->redis = new StreamingClient($this->stream, $this->parser);

        $ref = new \ReflectionProperty($this->redis, 'serializer');
        $ref->setAccessible(true);
        $serializer = $ref->getValue($this->redis);

        $this->assertInstanceOf(SerializerInterface::class, $serializer);
    }

    public function testSending()
    {
        $this->serializer->expects($this->once())->method('getRequestMessage')->with($this->equalTo('ping'))->will($this->returnValue('message'));
        $this->stream->expects($this->once())->method('write')->with($this->equalTo('message'));

        $this->redis->ping();
    }

    public function testClosingClientEmitsEvent()
    {
        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
    }

    public function testClosingStreamClosesClient()
    {
        $stream = new ThroughStream();
        $this->redis = new StreamingClient($stream, $this->parser, $this->serializer);

        $this->redis->on('close', $this->expectCallableOnce());

        $stream->emit('close');
    }

    public function testReceiveParseErrorEmitsErrorEvent()
    {
        $stream = new ThroughStream();
        $this->redis = new StreamingClient($stream, $this->parser, $this->serializer);

        $this->redis->on('error', $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\UnexpectedValueException::class),
                $this->callback(function (\UnexpectedValueException $e) {
                    return $e->getMessage() === 'Invalid data received: Foo (EBADMSG)';
                }),
                $this->callback(function (\UnexpectedValueException $e) {
                    return $e->getCode() === (defined('SOCKET_EBADMSG') ? SOCKET_EBADMSG : 77);
                })
            )
        ));
        $this->redis->on('close', $this->expectCallableOnce());

        $this->parser->expects($this->once())->method('pushIncoming')->with('message')->willThrowException(new ParserException('Foo'));
        $stream->emit('data', ['message']);
    }

    public function testReceiveUnexpectedReplyEmitsErrorEvent()
    {
        $stream = new ThroughStream();
        $this->redis = new StreamingClient($stream, $this->parser, $this->serializer);

        $this->redis->on('error', $this->expectCallableOnce());
        $this->redis->on('error', $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\UnderflowException::class),
                $this->callback(function (\UnderflowException $e) {
                    return $e->getMessage() === 'Unexpected reply received, no matching request found (ENOMSG)';
                }),
                $this->callback(function (\UnderflowException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOMSG') ? SOCKET_ENOMSG : 42);
                })
            )
        ));


        $this->parser->expects($this->once())->method('pushIncoming')->with('message')->willReturn([new IntegerReply(2)]);
        $stream->emit('data', ['message']);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDefaultCtor()
    {
        new StreamingClient($this->stream);
    }

    public function testPingPong()
    {
        $this->serializer->expects($this->once())->method('getRequestMessage')->with($this->equalTo('ping'));

        $promise = $this->redis->ping();

        $this->redis->handleMessage(new BulkReply('PONG'));

        $this->expectPromiseResolve($promise);
        $promise->then($this->expectCallableOnceWith('PONG'));
    }

    public function testMonitorCommandIsNotSupported()
    {
        $promise = $this->redis->monitor();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\BadMethodCallException::class),
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
        $promise = $this->redis->invalid();

        $err = new ErrorReply("ERR unknown command 'invalid'");
        $this->redis->handleMessage($err);

        $promise->then(null, $this->expectCallableOnceWith($err));
    }

    public function testClosingClientRejectsAllRemainingRequests()
    {
        $promise = $this->redis->ping();
        $this->redis->close();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
                })
            )
        ));
    }

    public function testClosingStreamRejectsAllRemainingRequests()
    {
        $stream = new ThroughStream(function () { return ''; });
        $this->parser->expects($this->once())->method('pushIncoming')->willReturn([]);
        $this->redis = new StreamingClient($stream, $this->parser, $this->serializer);

        $promise = $this->redis->ping();
        $stream->close();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closed by peer (ECONNRESET)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104);
                })
            )
        ));
    }

    public function testEndingClientRejectsAllNewRequests()
    {
        $this->redis->ping();
        $this->redis->end();
        $promise = $this->redis->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
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
        $this->redis->close();
        $promise = $this->redis->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
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
        $this->redis->on('close', $this->expectCallableOnce());
        $this->redis->end();
    }

    public function testEndingBusyClosesClientWhenNotBusyAnymore()
    {
        // count how often the "close" method has been called
        $closed = 0;
        $this->redis->on('close', function() use (&$closed) {
            ++$closed;
        });

        $promise = $this->redis->ping();
        $this->assertEquals(0, $closed);

        $this->redis->end();
        $this->assertEquals(0, $closed);

        $this->redis->handleMessage(new BulkReply('PONG'));
        $promise->then($this->expectCallableOnceWith('PONG'));
        $this->assertEquals(1, $closed);
    }

    public function testClosingMultipleTimesEmitsOnce()
    {
        $this->redis->on('close', $this->expectCallableOnce());

        $this->redis->close();
        $this->redis->close();
    }

    public function testReceivingUnexpectedMessageThrowsException()
    {
        $this->expectException(\UnderflowException::class);
        $this->redis->handleMessage(new BulkReply('PONG'));
    }

    public function testPubsubSubscribe()
    {
        $promise = $this->redis->subscribe('test');
        $this->expectPromiseResolve($promise);

        $this->redis->on('subscribe', $this->expectCallableOnce());
        $this->redis->handleMessage(new MultiBulkReply([new BulkReply('subscribe'), new BulkReply('test'), new IntegerReply(1)]));

        return $this->redis;
    }

    /**
     * @depends testPubsubSubscribe
     * @param StreamingClient $client
     */
    public function testPubsubPatternSubscribe(StreamingClient $client)
    {
         $promise = $client->psubscribe('demo_*');
         $this->expectPromiseResolve($promise);

         $client->on('psubscribe', $this->expectCallableOnce());
         $client->handleMessage(new MultiBulkReply([new BulkReply('psubscribe'), new BulkReply('demo_*'), new IntegerReply(1)]));

        return $client;
    }

    /**
     * @depends testPubsubPatternSubscribe
     * @param StreamingClient $client
     */
    public function testPubsubMessage(StreamingClient $client)
    {
        $client->on('message', $this->expectCallableOnce());
        $client->handleMessage(new MultiBulkReply([new BulkReply('message'), new BulkReply('test'), new BulkReply('payload')]));
    }

    public function testSubscribeWithMultipleArgumentsRejects()
    {
        $promise = $this->redis->subscribe('a', 'b');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\InvalidArgumentException::class),
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
        $promise = $this->redis->unsubscribe();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\InvalidArgumentException::class),
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
