<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\Promise\FulfilledPromise;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\Util;
use UnderflowException;
use RuntimeException;
use InvalidArgumentException;
use React\Promise\Deferred;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use React\Stream\DuplexStreamInterface;

/**
 * @internal
 */
class LazyStreamingClient extends EventEmitter implements Client
{
    private $target;
    /** @var Factory */
    private $factory;
    private $ending = false;
    private $closed = false;
    public $promise = null;
    public $client = null;

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

        if ($this->client instanceof Client) {
            return new FulfilledPromise($this->client());
        }

        $self = $this;
        return $this->promise = $this->factory->createClient($this->target)->then(function (Client $client) use ($self) {
            $self->client = $client;
            $self->promise = null;

            Util::forwardEvents(
                $self->client,
                $self,
                array(
                    'error',
                    'close',
                    'message',
                    'subscribe',
                    'unsubscribe',
                    'pmessage',
                    'psubscribe',
                    'punsubscribe',
                )
            );

            return $client;
        }, function (\Exception $e) use ($self) {
            // connection failed => emit error if connection is not already closed
            if ($self->closed) {
                return;
            }
            $self->emit('error', array($e));
            $self->close();

            return $e;
        });
    }

    public function __call($name, $args)
    {
        if ($this->client instanceof Client) {
            return \call_user_func_array(array($this->client, $name), $args);
        }

        return $this->client()->then(function (Client $client) use ($name, $args) {
            return \call_user_func_array(array($client, $name), $args);
        });
    }

    public function end()
    {
        if ($this->client instanceof Client) {
            return $this->client->end();
        }

        return $this->client()->then(function (Client $client) {
            return $client->end();
        });
    }

    public function close()
    {
        if ($this->client instanceof Client) {
            return $this->client->close();
        }

        return $this->client()->then(function (Client $client) {
            return $client->close();
        });
    }
}
