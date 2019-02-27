<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use React\Stream\Util;

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

    /**
     * @param $target
     */
    public function __construct($target, Factory $factory)
    {
        $this->target = $target;
        $this->factory = $factory;
    }

    private function client()
    {
        if ($this->promise !== null) {
            return $this->promise;
        }

        $self = $this;
        $pending =& $this->promise;
        return $pending = $this->factory->createClient($this->target)->then(function (Client $client) use ($self, &$pending) {
            // connection completed => remember only until closed
            $subscribed = array();
            $psubscribed = array();
            $client->on('close', function () use (&$pending, $self, &$subscribed, &$psubscribed) {
                $pending = null;

                // foward unsubscribe/punsubscribe events when underlying connection closes
                $n = count($subscribed);
                foreach ($subscribed as $channel => $_) {
                    $self->emit('unsubscribe', array($channel, --$n));
                }
                $n = count($psubscribed);
                foreach ($psubscribed as $pattern => $_) {
                    $self->emit('punsubscribe', array($pattern, --$n));
                }
            });

            // keep track of all channels and patterns this connection is subscribed to
            $client->on('subscribe', function ($channel) use (&$subscribed) {
                $subscribed[$channel] = true;
            });
            $client->on('psubscribe', function ($pattern) use (&$psubscribed) {
                $psubscribed[$pattern] = true;
            });
            $client->on('unsubscribe', function ($channel) use (&$subscribed) {
                unset($subscribed[$channel]);
            });
            $client->on('punsubscribe', function ($pattern) use (&$psubscribed) {
                unset($psubscribed[$pattern]);
            });

            Util::forwardEvents(
                $client,
                $self,
                array(
                    'message',
                    'subscribe',
                    'unsubscribe',
                    'pmessage',
                    'psubscribe',
                    'punsubscribe',
                )
            );

            return $client;
        }, function (\Exception $e) use (&$pending) {
            // connection failed => discard connection attempt
            $pending = null;

            throw $e;
        });
    }

    public function __call($name, $args)
    {
        if ($this->closed) {
            return \React\Promise\reject(new \RuntimeException('Connection closed'));
        }

        return $this->client()->then(function (Client $client) use ($name, $args) {
            return \call_user_func_array(array($client, $name), $args);
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

        $that = $this;
        return $this->client()->then(function (Client $client) use ($that) {
            $client->on('close', function () use ($that) {
                $that->close();
            });
            $client->end();
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
            $this->promise->then(function (Client $client) {
                $client->close();
            });
            $this->promise->cancel();
            $this->promise = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }
}
