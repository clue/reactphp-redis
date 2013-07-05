<?php

namespace Clue\Redis\React;

class Pipeline implements PromisorInterface
{
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->deferred = new Deferred();
    }

    public function __call($name, $args)
    {

    }

    public function uncork()
    {

    }

    public function getPromise()
    {
        return $this->deferred->getPromise();
    }
}
