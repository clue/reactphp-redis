<?php

namespace Clue\React\Redis;

use Clue\React\Redis\Io\Factory;
use Clue\React\Redis\Io\StreamingClient;
use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Stream\Util;
use function React\Promise\reject;

/**
 * Simple interface for executing redis commands
 *
 * @event error(Exception $error)
 * @event close()
 *
 * @event message($channel, $message)
 * @event subscribe($channel, $numberOfChannels)
 * @event unsubscribe($channel, $numberOfChannels)
 *
 * @event pmessage($pattern, $channel, $message)
 * @event psubscribe($channel, $numberOfChannels)
 * @event punsubscribe($channel, $numberOfChannels)
 */
class RedisClient extends EventEmitter
{
    /** @var string */
    private $uri;

    /** @var Factory */
    private $factory;

    /** @var bool */
    private $closed = false;

    /** @var ?PromiseInterface<StreamingClient> */
    private $promise = null;

    /** @var float */
    private $idlePeriod = 0.001;

    /** @var ?\React\EventLoop\TimerInterface */
    private $idleTimer = null;

    /** @var int */
    private $pending = 0;

    /** @var array<string,bool> */
    private $subscribed = [];

    /** @var array<string,bool> */
    private $psubscribed = [];

    /**
     * @param string $uri
     * @param ?ConnectorInterface $connector
     * @throws \InvalidArgumentException if $uri is not a valid Redis URI
     */
    public function __construct(string $uri, ?ConnectorInterface $connector = null)
    {
        // support `redis+unix://` scheme for Unix domain socket (UDS) paths
        if (preg_match('/^(redis\+unix:\/\/(?:[^@]*@)?)(.+)$/', $uri, $match)) {
            $parts = parse_url($match[1] . 'localhost/' . $match[2]);
        } else {
            if (strpos($uri, '://') === false) {
                $uri = 'redis://' . $uri;
            }

            $parts = parse_url($uri);
        }

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['redis', 'rediss', 'redis+unix'])) {
            $uri = (string) preg_replace(['/(:)[^:\/]*(@)/', '/([?&]password=).*?($|&)/'], '$1***$2', $uri);
            throw new \InvalidArgumentException(
                'Invalid Redis URI "' . $uri . '" (EINVAL)',
                defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
            );
        }

        $args = [];
        \parse_str($parts['query'] ?? '', $args);
        if (isset($args['idle'])) {
            $this->idlePeriod = (float)$args['idle'];
        }

        $this->uri = $uri;
        $this->factory = new Factory($connector);
    }

    /**
     * @return PromiseInterface<StreamingClient>
     */
    private function client(): PromiseInterface
    {
        if ($this->promise !== null) {
            return $this->promise;
        }

        return $this->promise = $this->factory->createClient($this->uri)->then(function (StreamingClient $redis) {
            // connection completed => remember only until closed
            $redis->on('close', function () {
                $this->promise = null;

                // forward unsubscribe/punsubscribe events when underlying connection closes
                $n = count($this->subscribed);
                foreach ($this->subscribed as $channel => $_) {
                    $this->emit('unsubscribe', [$channel, --$n]);
                }
                $n = count($this->psubscribed);
                foreach ($this->psubscribed as $pattern => $_) {
                    $this->emit('punsubscribe', [$pattern, --$n]);
                }
                $this->subscribed = $this->psubscribed = [];

                if ($this->idleTimer !== null) {
                    Loop::cancelTimer($this->idleTimer);
                    $this->idleTimer = null;
                }
            });

            // keep track of all channels and patterns this connection is subscribed to
            $redis->on('subscribe', function (string $channel) {
                $this->subscribed[$channel] = true;
            });
            $redis->on('psubscribe', function (string $pattern) {
                $this->psubscribed[$pattern] = true;
            });
            $redis->on('unsubscribe', function (string $channel) {
                unset($this->subscribed[$channel]);
            });
            $redis->on('punsubscribe', function (string $pattern) {
                unset($this->psubscribed[$pattern]);
            });

            Util::forwardEvents(
                $redis,
                $this,
                [
                    'message',
                    'subscribe',
                    'unsubscribe',
                    'pmessage',
                    'psubscribe',
                    'punsubscribe',
                ]
            );

            return $redis;
        }, function (\Throwable $e) {
            assert($e instanceof \Exception);

            // connection failed => discard connection attempt
            $this->promise = null;

            throw $e;
        });
    }

    /**
     * Invoke the given command and return a Promise that will be resolved when the command has been replied to
     *
     * This is a magic method that will be invoked when calling any redis
     * command on this instance. See also `RedisClient::callAsync()`.
     *
     * @param string   $name
     * @param string[] $args
     * @return PromiseInterface<mixed>
     * @see self::callAsync()
     */
    public function __call(string $name, array $args): PromiseInterface
    {
        return $this->callAsync($name, ...$args);
    }

    /**
     * Invoke a Redis command.
     *
     * For example, the [`GET` command](https://redis.io/commands/get) can be invoked
     * like this:
     *
     * ```php
     * $redis->callAsync('GET', 'name')->then(function (?string $name): void {
     *     echo 'Name: ' . ($name ?? 'Unknown') . PHP_EOL;
     * }, function (Throwable $e): void {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * The `string $command` parameter can be any valid Redis command. All
     * [Redis commands](https://redis.io/commands/) are available through this
     * method. As an alternative, you may also use the magic
     * [`__call()` method](#__call), but note that not all static analysis tools
     * may understand this magic method. Listing all available commands is out
     * of scope here, please refer to the
     * [Redis command reference](https://redis.io/commands).
     *
     * The optional `string ...$args` parameter can be used to pass any
     * additional arguments to the Redis command. Some commands may require or
     * support additional arguments that this method will simply forward as is.
     * Internally, Redis requires all arguments to be coerced to `string` values,
     * but you may also rely on PHP's type-juggling semantics and pass `int` or
     * `float` values:
     *
     * ```php
     * $redis->callAsync('SET', 'name', 'Alice', 'EX', 600);
     * ```
     *
     * This method supports async operation and returns a [Promise](#promises)
     * that eventually *fulfills* with its *results* on success or *rejects*
     * with an `Exception` on error. See also [promises](#promises) for more
     * details.
     *
     * @param string $command
     * @param string ...$args
     * @return PromiseInterface<mixed>
     * @throws void
     */
    public function callAsync(string $command, string ...$args): PromiseInterface
    {
        if ($this->closed) {
            return reject(new \RuntimeException(
                'Connection closed (ENOTCONN)',
                defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107
            ));
        }

        return $this->client()->then(function (StreamingClient $redis) use ($command, $args): PromiseInterface {
            $this->awake();
            return $redis->callAsync($command, ...$args)->then(
                function ($result) {
                    $this->idle();
                    return $result;
                },
                function (\Throwable $e) {
                    \assert($e instanceof \Exception);
                    $this->idle();
                    throw $e;
                }
            );
        });
    }

    /**
     * end connection once all pending requests have been replied to
     *
     * @return void
     * @uses self::close() once all replies have been received
     * @see self::close() for closing the connection immediately
     */
    public function end(): void
    {
        if ($this->promise === null) {
            $this->close();
        }

        if ($this->closed) {
            return;
        }

        $this->client()->then(function (StreamingClient $redis) {
            $redis->on('close', function () {
                $this->close();
            });
            $redis->end();
        });
    }

    /**
     * close connection immediately
     *
     * This will emit the "close" event.
     *
     * @return void
     * @see self::end() for closing the connection once the client is idle
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // either close active connection or cancel pending connection attempt
        if ($this->promise !== null) {
            $this->promise->then(function (StreamingClient $redis) {
                $redis->close();
            }, function () {
                // ignore to avoid reporting unhandled rejection
            });
            if ($this->promise !== null) {
                $this->promise->cancel();
                $this->promise = null;
            }
        }

        if ($this->idleTimer !== null) {
            Loop::cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function awake(): void
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            Loop::cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    private function idle(): void
    {
        --$this->pending;

        if ($this->pending < 1 && $this->idlePeriod >= 0 && !$this->subscribed && !$this->psubscribed && $this->promise !== null) {
            $this->idleTimer = Loop::addTimer($this->idlePeriod, function () {
                assert($this->promise instanceof PromiseInterface);
                $this->promise->then(function (StreamingClient $redis) {
                    $redis->close();
                });
                $this->promise = null;
                $this->idleTimer = null;
            });
        }
    }
}
