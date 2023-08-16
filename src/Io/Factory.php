<?php

namespace Clue\React\Redis\Io;

use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use function React\Promise\reject;
use function React\Promise\Timer\timeout;

/**
 * @internal
 */
class Factory
{
    /** @var LoopInterface */
    private $loop;

    /** @var ConnectorInterface */
    private $connector;

    /** @var ProtocolFactory */
    private $protocol;

    /**
     * @param ?LoopInterface $loop
     * @param ?ConnectorInterface $connector
     * @param ?ProtocolFactory $protocol
     */
    public function __construct(LoopInterface $loop = null, ConnectorInterface $connector = null, ProtocolFactory $protocol = null)
    {
        $this->loop = $loop ?: Loop::get();
        $this->connector = $connector ?: new Connector([], $this->loop);
        $this->protocol = $protocol ?: new ProtocolFactory();
    }

    /**
     * Create Redis client connected to address of given redis instance
     *
     * @param string $uri Redis server URI to connect to
     * @return PromiseInterface<StreamingClient> Promise that will
     *     be fulfilled with `StreamingClient` on success or rejects with `\Exception` on error.
     */
    public function createClient(string $uri): PromiseInterface
    {
        // support `redis+unix://` scheme for Unix domain socket (UDS) paths
        if (preg_match('/^(redis\+unix:\/\/(?:[^:]*:[^@]*@)?)(.+?)?$/', $uri, $match)) {
            $parts = parse_url($match[1] . 'localhost/' . $match[2]);
        } else {
            if (strpos($uri, '://') === false) {
                $uri = 'redis://' . $uri;
            }

            $parts = parse_url($uri);
        }

        $uri = preg_replace(['/(:)[^:\/]*(@)/', '/([?&]password=).*?($|&)/'], '$1***$2', $uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['redis', 'rediss', 'redis+unix'])) {
            return reject(new \InvalidArgumentException(
                'Invalid Redis URI given (EINVAL)',
                defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
            ));
        }

        $args = [];
        parse_str($parts['query'] ?? '', $args);

        $authority = $parts['host'] . ':' . ($parts['port'] ?? 6379);
        if ($parts['scheme'] === 'rediss') {
            $authority = 'tls://' . $authority;
        } elseif ($parts['scheme'] === 'redis+unix') {
            assert(isset($parts['path']));
            $authority = 'unix://' . substr($parts['path'], 1);
            unset($parts['path']);
        }

        /** @var PromiseInterface<ConnectionInterface> $connecting */
        $connecting = $this->connector->connect($authority);

        $deferred = new Deferred(function ($_, $reject) use ($connecting, $uri) {
            // connection cancelled, start with rejecting attempt, then clean up
            $reject(new \RuntimeException(
                'Connection to ' . $uri . ' cancelled (ECONNABORTED)',
                defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
            ));

            // either close successful connection or cancel pending connection attempt
            $connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            }, function () {
                // ignore to avoid reporting unhandled rejection
            });
            assert(\method_exists($connecting, 'cancel'));
            $connecting->cancel();
        });

        $promise = $connecting->then(function (ConnectionInterface $stream) {
            return new StreamingClient($stream, $this->protocol->createResponseParser(), $this->protocol->createSerializer());
        }, function (\Throwable $e) use ($uri) {
            throw new \RuntimeException(
                'Connection to ' . $uri . ' failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        });

        // use `?password=secret` query or `user:secret@host` password form URL
        if (isset($args['password']) || isset($parts['pass'])) {
            $pass = $args['password'] ?? rawurldecode($parts['pass']); // @phpstan-ignore-line
            $promise = $promise->then(function (StreamingClient $redis) use ($pass, $uri) {
                return $redis->auth($pass)->then(
                    function () use ($redis) {
                        return $redis;
                    },
                    function (\Exception $e) use ($redis, $uri) {
                        $redis->close();

                        $const = '';
                        $errno = $e->getCode();
                        if ($errno === 0) {
                            $const = ' (EACCES)';
                            $errno = $e->getCode() ?: (defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
                        }

                        throw new \RuntimeException(
                            'Connection to ' . $uri . ' failed during AUTH command: ' . $e->getMessage() . $const,
                            $errno,
                            $e
                        );
                    }
                );
            });
        }

        // use `?db=1` query or `/1` path (skip first slash)
        if (isset($args['db']) || (isset($parts['path']) && $parts['path'] !== '/')) {
            $db = $args['db'] ?? substr($parts['path'], 1); // @phpstan-ignore-line
            $promise = $promise->then(function (StreamingClient $redis) use ($db, $uri) {
                return $redis->select($db)->then(
                    function () use ($redis) {
                        return $redis;
                    },
                    function (\Exception $e) use ($redis, $uri) {
                        $redis->close();

                        $const = '';
                        $errno = $e->getCode();
                        if ($errno === 0 && strpos($e->getMessage(), 'NOAUTH ') === 0) {
                            $const = ' (EACCES)';
                            $errno = defined('SOCKET_EACCES') ? SOCKET_EACCES : 13;
                        } elseif ($errno === 0) {
                            $const = ' (ENOENT)';
                            $errno = defined('SOCKET_ENOENT') ? SOCKET_ENOENT : 2;
                        }

                        throw new \RuntimeException(
                            'Connection to ' . $uri . ' failed during SELECT command: ' . $e->getMessage() . $const,
                            $errno,
                            $e
                        );
                    }
                );
            });
        }

        $promise->then([$deferred, 'resolve'], [$deferred, 'reject']);

        // use timeout from explicit ?timeout=x parameter or default to PHP's default_socket_timeout (60)
        $timeout = isset($args['timeout']) ? (float) $args['timeout'] : (int) ini_get("default_socket_timeout");
        if ($timeout < 0) {
            return $deferred->promise();
        }

        return timeout($deferred->promise(), $timeout, $this->loop)->then(null, function (\Throwable $e) use ($uri) {
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection to ' . $uri . ' timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }
}
