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
            $client->on('close', function () use (&$pending) {
                $pending = null;
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
