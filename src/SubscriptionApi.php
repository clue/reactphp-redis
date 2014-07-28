<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;

/**
 * http://redis.io/topics/pubsub
 * http://redis.io/commands#pubsub
 */
class SubscriptionApi extends EventEmitter
{
    private $client;
    private $requestApi;

    public function __construct(Client $client, RequestApi $requestApi = null)
    {
        if ($requestApi === null) {
            $requestApi = new RequestApi($client);
        }

        $this->client = $client;
        $this->requestApi = $requestApi;

        $this->client->on('message', array($this, 'handleMessage'));
    }

    public function subscribe($channel)
    {
        return $this->respond('subscribe', func_get_args());
    }

    public function psubscribe($pattern)
    {
        return $this->respond('psubscribe', func_get_args());
    }

    public function unsubscribe($channel = null)
    {

    }

    public function publish($channel, $message)
    {
        return $this->requestApi->publish($channel, $message);
    }

    private function respond($name, $args)
    {
        return call_user_func_array(array($this->requestApi, $name), $args);
    }

    public function handleMessage(ModelInterface $message)
    {
        if (!($message instanceof MultiBulkReply)) {
            return;
        }

        $parts = $message->getValueNative();
        if (count($parts) !== 3) {
            return;
        }

        $name = array_shift($parts);
        $this->emit($name, $parts);
    }
}
