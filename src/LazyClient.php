<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use React\Promise\PromiseInterface;
use React\Stream\Util;

/**
 * @internal
 */
class LazyClient extends EventEmitter implements Client
{
    private $target;
    /** @var Factory */
    private $factory;
    private $ending = false;
    private $closed = false;
    private $promise;

    /**
     * @param $target
     */
    public function __construct($target, Factory $factory)
    {
        $this->target = $target;
        $this->factory = $factory;

        $this->on('close', array($this, 'removeAllListeners'));
    }

    private function client()
    {
        if ($this->promise instanceof PromiseInterface) {
            return $this->promise;
        }

        $self = $this;
        return $this->promise = $this->factory->createClient($this->target)->then(function (Client $client) use ($self) {
            Util::forwardEvents(
                $client,
                $self,
                array(
                    'error',
                    'message',
                    'subscribe',
                    'unsubscribe',
                    'pmessage',
                    'psubscribe',
                    'punsubscribe',
                )
            );

            $client->on('close', array($self, 'close'));

            return $client;
        }, function (\Exception $e) use ($self) {
            // connection failed => emit error if connection is not already closed
            if ($self->closed) {
                return;
            }
            $self->emit('error', array($e));
            $self->close();

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

        return $this->client()->then(function (Client $client) {
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
