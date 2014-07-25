<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;

/**
 * http://redis.io/topics/pubsub
 * http://redis.io/commands#pubsub
 */
class SubscriptionApi extends EventEmitter
{
    private $client;
    private $responseApi;

    public function __construct(Client $client, ResponseApi $responseApi = null)
    {
        if ($responseApi === null) {
            $responseApi = new ResponseApi($client);
        }

        $this->client = $client;
        $this->responseApi = $responseApi;
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
        return $this->responseApi->publish($channel, $message);
    }

    private function respond($name, $args)
    {
        return call_user_func_array(array($this->responseApi, $name), $args);
    }
}
