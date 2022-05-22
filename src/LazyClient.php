<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use React\Stream\Util;
use function React\Promise\reject;

/**
 * @internal
 */
class LazyClient extends EventEmitter implements Client
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
    private $idlePeriod = 60.0;

    /** @var ?TimerInterface */
    private $idleTimer = null;

    /** @var int */
    private $pending = 0;

    /** @var array<string,bool> */
    private $subscribed = [];

    /** @var array<string,bool> */
    private $psubscribed = [];

    public function __construct(string $target, Factory $factory, LoopInterface $loop)
    {
        $args = [];
        \parse_str((string) \parse_url($target, \PHP_URL_QUERY), $args);
        if (isset($args['idle'])) {
            $this->idlePeriod = (float)$args['idle'];
        }

        $this->target = $target;
        $this->factory = $factory;
        $this->loop = $loop;
    }

    private function client(): PromiseInterface
    {
        if ($this->promise !== null) {
            return $this->promise;
        }

        return $this->promise = $this->factory->createClient($this->target)->then(function (Client $redis) {
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

    public function __call(string $name, array $args): PromiseInterface
    {
        if ($this->closed) {
            return reject(new \RuntimeException(
                'Connection closed (ENOTCONN)',
                defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107
            ));
        }

        return $this->client()->then(function (Client $redis) use ($name, $args) {
            $this->awake();
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

    public function end(): void
    {
        if ($this->promise === null) {
            $this->close();
        }

        if ($this->closed) {
            return;
        }

        $this->client()->then(function (Client $redis) {
            $redis->on('close', function () {
                $this->close();
            });
            $redis->end();
        });
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // either close active connection or cancel pending connection attempt
        if ($this->promise !== null) {
            $this->promise->then(function (Client $redis) {
                $redis->close();
            });
            if ($this->promise !== null) {
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
                $this->promise->then(function (Client $redis) {
                    $redis->close();
                });
                $this->promise = null;
                $this->idleTimer = null;
            });
        }
    }
}
