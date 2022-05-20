<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use React\Stream\Util;
use React\EventLoop\LoopInterface;
use function React\Promise\reject;

/**
 * @internal
 */
class LazyClient extends EventEmitter implements Client
{
    private $target;
    /** @var Factory */
    private $factory;
    private $closed = false;
    private $promise;

    private $loop;
    private $idlePeriod = 60.0;
    private $idleTimer;
    private $pending = 0;

    private $subscribed = [];
    private $psubscribed = [];

    /**
     * @param $target
     */
    public function __construct($target, Factory $factory, LoopInterface $loop)
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

    private function client()
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
            $redis->on('subscribe', function ($channel) {
                $this->subscribed[$channel] = true;
            });
            $redis->on('psubscribe', function ($pattern) {
                $this->psubscribed[$pattern] = true;
            });
            $redis->on('unsubscribe', function ($channel) {
                unset($this->subscribed[$channel]);
            });
            $redis->on('punsubscribe', function ($pattern) {
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

    public function __call($name, $args)
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
                function ($error) {
                    $this->idle();
                    throw $error;
                }
            );
        });
    }

    public function end()
    {
        if ($this->promise === null) {
            $this->close();
        }

        if ($this->closed) {
            return;
        }

        return $this->client()->then(function (Client $redis) {
            $redis->on('close', function () {
                $this->close();
            });
            $redis->end();
        });
    }

    public function close()
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

    /**
     * @internal
     */
    public function awake()
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    /**
     * @internal
     */
    public function idle()
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
