<?php

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

class FactoryStreamingClientTest extends TestCase
{
    /** @var MockObject */
    private $loop;

    /** @var MockObject */
    private $connector;

    /** @var Factory */
    private $factory;

    public function setUp(): void
    {
        $this->loop = $this->createMock(LoopInterface::class);
        $this->connector = $this->createMock(ConnectorInterface::class);
        $this->factory = new Factory($this->loop, $this->connector);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf(LoopInterface::class, $loop);
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
        $this->connector->expects($this->once())->method('connect')->with('redis.example.com:6379')->willReturn(reject(new \RuntimeException()));
        $this->factory->createClient('redis.example.com');
    }

    public function testWillConnectToLocalhost()
    {
        $this->connector->expects($this->once())->method('connect')->with('localhost:1337')->willReturn(reject(new \RuntimeException()));
        $this->factory->createClient('localhost:1337');
    }

    public function testWillResolveIfConnectorResolves()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('localhost');

        $this->expectPromiseResolve($promise);
    }

    public function testWillWriteSelectCommandIfTargetContainsPath()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $this->factory->createClient('redis://127.0.0.1/demo');
    }

    public function testWillWriteSelectCommandIfTargetContainsDbQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$1\r\n4\r\n");

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $this->factory->createClient('redis://127.0.0.1?db=4');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createClient('redis://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUriContainsEncodedUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createClient('redis://:h%40llo@example.com');
    }

    public function testWillWriteAuthCommandIfTargetContainsPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$6\r\nsecret\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createClient('redis://example.com?password=secret');
    }

    public function testWillWriteAuthCommandIfTargetContainsEncodedPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nh@llo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('example.com:6379')->willReturn(resolve($stream));
        $this->factory->createClient('redis://example.com?password=h%40llo');
    }

    public function testWillWriteAuthCommandIfRedissUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('tls://example.com:6379')->willReturn(resolve($stream));
        $this->factory->createClient('rediss://hello:world@example.com');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsPasswordQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?password=world');
    }

    public function testWillNotWriteAnyCommandIfRedisUnixUriContainsNoPasswordOrDb()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->never())->method('write');

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock');
    }

    public function testWillWriteAuthCommandIfRedisUnixUriContainsUserInfo()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createClient('redis+unix://hello:world@/tmp/redis.sock');
    }

    public function testWillResolveWhenAuthCommandReceivesOkResponseIfRedisUriContainsUserInfo()
    {
        $dataHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })],
            ['close', $this->anything()]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://:world@localhost');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("+OK\r\n");

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Client::class)));
    }

    public function testWillRejectAndCloseAutomaticallyWhenAuthCommandReceivesErrorResponseIfRedisUriContainsUserInfo()
    {
        $dataHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })],
            ['close', $this->anything()]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://:world@localhost');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("-ERR invalid password\r\n");

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://:***@localhost failed during AUTH command: ERR invalid password (EACCES)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getPrevious()->getMessage() === 'ERR invalid password';
                })
            )
        ));
    }

    public function testWillRejectAndCloseAutomaticallyWhenConnectionIsClosedWhileWaitingForAuthCommand()
    {
        $closeHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$4\r\nauth\r\n$5\r\nworld\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->anything()],
            ['close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            })]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://:world@localhost');

        $this->assertTrue(is_callable($closeHandler));
        $stream->expects($this->once())->method('isReadable')->willReturn(false);
        $stream->expects($this->once())->method('isWritable')->willReturn(false);
        call_user_func($closeHandler);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to redis://:***@localhost failed during AUTH command: Connection closed by peer (ECONNRESET)';
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104);
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getPrevious()->getMessage() === 'Connection closed by peer (ECONNRESET)';
                })
            )
        ));
    }

    public function testWillWriteSelectCommandIfRedisUnixUriContainsDbQueryParameter()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$4\r\ndemo\r\n");

        $this->connector->expects($this->once())->method('connect')->with('unix:///tmp/redis.sock')->willReturn(resolve($stream));
        $this->factory->createClient('redis+unix:///tmp/redis.sock?db=demo');
    }

    public function testWillResolveWhenSelectCommandReceivesOkResponseIfRedisUriContainsPath()
    {
        $dataHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })],
            ['close', $this->anything()]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("+OK\r\n");

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Client::class)));
    }

    public function testWillRejectAndCloseAutomaticallyWhenSelectCommandReceivesErrorResponseIfRedisUriContainsPath()
    {
        $dataHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })],
            ['close', $this->anything()]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("-ERR DB index is out of range\r\n");

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://localhost/123 failed during SELECT command: ERR DB index is out of range (ENOENT)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOENT') ? SOCKET_ENOENT : 2);
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getPrevious()->getMessage() === 'ERR DB index is out of range';
                })
            )
        ));
    }

    public function testWillRejectAndCloseAutomaticallyWhenSelectCommandReceivesAuthErrorResponseIfRedisUriContainsPath()
    {
        $dataHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->callback(function ($arg) use (&$dataHandler) {
                $dataHandler = $arg;
                return true;
            })],
            ['close', $this->anything()]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($dataHandler));
        $dataHandler("-NOAUTH Authentication required.\r\n");

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to redis://localhost/123 failed during SELECT command: NOAUTH Authentication required. (EACCES)';
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getCode() === (defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getPrevious()->getMessage() === 'NOAUTH Authentication required.';
                })
            )
        ));
    }

    public function testWillRejectAndCloseAutomaticallyWhenConnectionIsClosedWhileWaitingForSelectCommand()
    {
        $closeHandler = null;
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write')->with("*2\r\n$6\r\nselect\r\n$3\r\n123\r\n");
        $stream->expects($this->once())->method('close');
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            ['data', $this->anything()],
            ['close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            })]
        );

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));
        $promise = $this->factory->createClient('redis://localhost/123');

        $this->assertTrue(is_callable($closeHandler));
        $stream->expects($this->once())->method('isReadable')->willReturn(false);
        $stream->expects($this->once())->method('isWritable')->willReturn(false);
        call_user_func($closeHandler);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to redis://localhost/123 failed during SELECT command: Connection closed by peer (ECONNRESET)';
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104);
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getPrevious()->getMessage() === 'Connection closed by peer (ECONNRESET)';
                })
            )
        ));
    }

    public function testWillRejectIfConnectorRejects()
    {
        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:2')->willReturn(reject(new \RuntimeException('Foo', 42)));
        $promise = $this->factory->createClient('redis://127.0.0.1:2');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2 failed: Foo';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === 42;
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
                $this->isInstanceOf(\InvalidArgumentException::class),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'Invalid Redis URI given (EINVAL)';
                }),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getCode() === (defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22);
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

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf(\RuntimeException::class)));
    }

    public function provideUris()
    {
        return [
            [
                'localhost',
                'redis://localhost'
            ],
            [
                'redis://localhost',
                'redis://localhost'
            ],
            [
                'redis://localhost:6379',
                'redis://localhost:6379'
            ],
            [
                'redis://localhost/0',
                'redis://localhost/0'
            ],
            [
                'redis://user@localhost',
                'redis://user@localhost'
            ],
            [
                'redis://:secret@localhost',
                'redis://:***@localhost'
            ],
            [
                'redis://user:secret@localhost',
                'redis://user:***@localhost'
            ],
            [
                'redis://:@localhost',
                'redis://:***@localhost'
            ],
            [
                'redis://localhost?password=secret',
                'redis://localhost?password=***'
            ],
            [
                'redis://localhost/0?password=secret',
                'redis://localhost/0?password=***'
            ],
            [
                'redis://localhost?password=',
                'redis://localhost?password=***'
            ],
            [
                'redis://localhost?foo=1&password=secret&bar=2',
                'redis://localhost?foo=1&password=***&bar=2'
            ],
            [
                'rediss://localhost',
                'rediss://localhost'
            ],
            [
                'redis+unix://:secret@/tmp/redis.sock',
                'redis+unix://:***@/tmp/redis.sock'
            ],
            [
                'redis+unix:///tmp/redis.sock?password=secret',
                'redis+unix:///tmp/redis.sock?password=***'
            ]
        ];
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
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) use ($safe) {
                    return $e->getMessage() === 'Connection to ' . $safe . ' cancelled (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
                })
            )
        ));
    }

    public function testCancelWillCloseConnectionWhenConnectionWaitsForSelect()
    {
        $stream = $this->createMock(ConnectionInterface::class);
        $stream->expects($this->once())->method('write');
        $stream->expects($this->once())->method('close');

        $this->connector->expects($this->once())->method('connect')->willReturn(resolve($stream));

        $promise = $this->factory->createClient('redis://127.0.0.1:2/123');
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2/123 cancelled (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
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
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to redis://127.0.0.1:2?timeout=0 timed out after 0 seconds (ETIMEDOUT)';
                }),
                $this->callback(function (\Exception $e) {
                    return $e->getCode() === (defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110);
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
