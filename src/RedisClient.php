<?php

namespace Clue\React\Redis;

use Clue\React\Redis\Io\Factory;
use Clue\React\Redis\Io\StreamingClient;
use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
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
    private $target;

    /** @var Factory */
    private $factory;

    /** @var bool */
    private $closed = false;

    /** @var ?PromiseInterface */
    private $promise = null;

    /** @var LoopInterface */
    private $loop;

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
     * @param string $url
     * @param ?ConnectorInterface $connector
     * @param ?LoopInterface $loop
     */
    public function __construct($url, ConnectorInterface $connector = null, LoopInterface $loop = null)
    {
        $args = [];
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $args);
        if (isset($args['idle'])) {
            $this->idlePeriod = (float)$args['idle'];
        }

        $this->target = $url;
        $this->loop = $loop ?: Loop::get();
        $this->factory = new Factory($this->loop, $connector);
    }

    private function client(): PromiseInterface
    {
        if ($this->promise !== null) {
            return $this->promise;
        }

        return $this->promise = $this->factory->createClient($this->target)->then(function (StreamingClient $redis) {
            // connection completed => remember only until closed
            $redis->on('close', function () {
                $this->promise = null;

                // foward unsubscribe/punsubscribe events when underlying connection closes
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
                    $this->loop->cancelTimer($this->idleTimer);
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
        }, function (\Exception $e) {
            // connection failed => discard connection attempt
            $this->promise = null;

            throw $e;
        });
    }

    /**
     * Invoke the given command and return a Promise that will be resolved when the request has been replied to
     *
     * This is a magic method that will be invoked when calling any redis
     * command on this instance.
     *
     * @param string   $name
     * @param string[] $args
     * @return PromiseInterface Promise<mixed,Exception>
     */
    public function __call(string $name, array $args): PromiseInterface
    {
        if ($this->closed) {
            return reject(new \RuntimeException(
                'Connection closed (ENOTCONN)',
                defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107
            ));
        }

        return $this->client()->then(function (StreamingClient $redis) use ($name, $args) {
            $this->awake();
            assert(\is_callable([$redis, $name])); // @phpstan-ignore-next-line
            return \call_user_func_array([$redis, $name], $args)->then(
                function ($result) {
                    $this->idle();
                    return $result;
                },
                function (\Exception $error) {
                    $this->idle();
                    throw $error;
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
                assert(\method_exists($this->promise, 'cancel'));
                $this->promise->cancel();
                $this->promise = null;
            }
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function awake(): void
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    private function idle(): void
    {
        --$this->pending;

        if ($this->pending < 1 && $this->idlePeriod >= 0 && !$this->subscribed && !$this->psubscribed && $this->promise !== null) {
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () {
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
