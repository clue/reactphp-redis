<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Factory;
use React\Promise;
use React\Promise\Deferred;

class FactoryStreamingClientTest extends TestCase
{
    private $loop;
    private $connector;
    private $factory;

    /**
     * @before
     */
    public function setUpFactory()
    {
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->factory = new Factory($this->loop, $this->connector);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testCtor()
    {
        $this->factory = new Factory($this->loop);
    }

    public function testWillConnectWithDefaultPort()
    {
        $this->connector->expects($this->once())->method('connect')->with('redis.example.com:6379')->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('redis.example.com');
    }

    public function testWillConnectToLocalhost()
    {
        $this->connector->expects($this->once())->method('connect')->with('localhost:1337')->willReturn(Promise\reject(new \RuntimeException()));
        $this->factory->createClient('localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('localhost');

        $this->expectPromiseResolve($promise);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://127.0.0.1/demo');
    }

    public function testWillWriteSelectCommandIfTargetContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$1\r\n4\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://127.0.0.1?db=4');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsEncodedUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://:h%40llo@example.com');
    }

    public function testWillWriteAuthCommandIfTargetContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$6\r\nsecret\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://example.com?password=secret');
    }

    public function testWillWriteAuthCommandIfTargetContainsEncodedPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis://example.com?password=h%40llo');
    }

    public function testWillWriteAuthCommandIfRedissUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('tls://example.com:6379')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('rediss://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsPasswordQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?password=world');
    }

    public function testWillNotWriteAnyCommandIfRedisUnixUriContainsNoPasswordOrDb()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsUserInfo()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix://hello:world@/tmp/redis.sock');
    }

    public function testWillResolveWhenAuthCommandReceivesOkResponseIfRedisUriContainsUserInfo()
    {
        $dataHandler = null;
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            array('data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })),
            array('close', $this->anything())
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('redis://:world@localhost');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("+OK\r\n");

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('Clue\React\Redis\Client')));
    }

    public function testWillRejectAndCloseAutomaticallyWhenAuthCommandReceivesErrorResponseIfRedisUriContainsUserInfo()
    {
        $dataHandler = null;
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            array('data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })),
            array('close', $this->anything())
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('redis://:world@localhost');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("-ERR invalid password\r\n");

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://:***@localhost failed during AUTH command: ERR invalid password';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getPrevious()->getMessage() === 'ERR invalid password';
                })
            )
        ));
    }

    public function testWillWriteSelectCommandIfRedisUnixUriContainsDbQueryParameter()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(Promise\resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?db=demo');
    }

    public function testWillResolveWhenSelectCommandReceivesOkResponseIfRedisUriContainsPath()
    {
        $dataHandler = null;
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            array('data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })),
            array('close', $this->anything())
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("+OK\r\n");

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('Clue\React\Redis\Client')));
    }

    public function testWillRejectAndCloseAutomaticallyWhenSelectCommandReceivesErrorResponseIfRedisUriContainsPath()
    {
        $dataHandler = null;
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            array('data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })),
            array('close', $this->anything())
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("-ERR DB index is out of range\r\n");

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://localhost/123 failed during SELECT command: ERR DB index is out of range';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getPrevious()->getMessage() === 'ERR DB index is out of range';
                })
            )
        ));
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn(Promise\reject(new \RuntimeException('Foo', 42)));
        $promise = $this->factory->createClient('redis://127.0.0.1:2');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2 failed: Foo';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getPrevious()->getMessage() === 'Foo';
                })
            )
        ));
    }

    public function testWillRejectIfTargetIsInvalid()
    {
        $promise = $this->factory->createClient('http://invalid target');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('InvalidArgumentException'),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'Invalid Redis URI given';
                })
            )
        ));
    }

    public function testCancelWillRejectPromise()
    {
        $promise = new \React\Promise\Promise(function () { });
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($promise);

        $promise = $this->factory->createClient('redis://127.0.0.1:2');
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
    }

    public function provideUris()
    {
        return array(
            array(
                'localhost',
                'redis://localhost'
            ),
            array(
                'redis://localhost',
                'redis://localhost'
            ),
            array(
                'redis://localhost:6379',
                'redis://localhost:6379'
            ),
            array(
                'redis://localhost/0',
                'redis://localhost/0'
            ),
            array(
                'redis://user@localhost',
                'redis://user@localhost'
            ),
            array(
                'redis://:secret@localhost',
                'redis://:***@localhost'
            ),
            array(
                'redis://user:secret@localhost',
                'redis://user:***@localhost'
            ),
            array(
                'redis://:@localhost',
                'redis://:***@localhost'
            ),
            array(
                'redis://localhost?password=secret',
                'redis://localhost?password=***'
            ),
            array(
                'redis://localhost/0?password=secret',
                'redis://localhost/0?password=***'
            ),
            array(
                'redis://localhost?password=',
                'redis://localhost?password=***'
            ),
            array(
                'redis://localhost?foo=1&password=secret&bar=2',
                'redis://localhost?foo=1&password=***&bar=2'
            ),
            array(
                'rediss://localhost',
                'rediss://localhost'
            ),
            array(
                'redis+unix://:secret@/tmp/redis.sock',
                'redis+unix://:***@/tmp/redis.sock'
            ),
            array(
                'redis+unix:///tmp/redis.sock?password=secret',
                'redis+unix:///tmp/redis.sock?password=***'
            )
        );
    }

    /**
     * @dataProvider provideUris
     * @param string $uri
     * @param string $safe
     */
    public function testCancelWillRejectWithUriInMessageAndCancelConnectorWhenConnectionIsPending($uri, $safe)
    {
        $deferred = new Deferred($this->expectCallableOnce());
        $this->connector->expects($this->once())->method('connect')->willReturn($deferred->promise());

        $promise = $this->factory->createClient($uri);
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) use ($safe) {
                    return $e->getMessage() === 'Connection to ' . $safe . ' cancelled';
                })
            )
        ));
    }

    public function testCancelWillCloseConnectionWhenConnectionWaitsForSelect()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('write');
        $stream->expects($this->once())->method('close');

        $this->connector->expects($this->once())->method('connect')->willReturn(Promise\resolve($stream));

        $promise = $this->factory->createClient('redis://127.0.0.1:2/123');
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2/123 cancelled';
                })
            )
        ));
    }

    public function testCreateClientWithTimeoutParameterWillStartTimerAndRejectOnExplicitTimeout()
    {
        $timeout = null;
        $this->loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }));

        $deferred = new Deferred();
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($deferred->promise());

        $promise = $this->factory->createClient('redis://127.0.0.1:2?timeout=0');

        $this->assertNotNull($timeout);
        $timeout();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2?timeout=0 timed out after 0 seconds';
                })
            )
        ));
    }

    public function testCreateClientWithNegativeTimeoutParameterWillNotStartTimer()
    {
        $this->loop->expects($this->never())->method('addTimer');

        $deferred = new Deferred();
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($deferred->promise());

        $this->factory->createClient('redis://127.0.0.1:2?timeout=-1');
    }

    public function testCreateClientWithoutTimeoutParameterWillStartTimerWithDefaultTimeoutFromIni()
    {
        $this->loop->expects($this->once())->method('addTimer')->with(42, $this->anything());

        $deferred = new Deferred();
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn($deferred->promise());

        $old = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '42');
        $this->factory->createClient('redis://127.0.0.1:2');
        ini_set('default_socket_timeout', $old);
    }
}
