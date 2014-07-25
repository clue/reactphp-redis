<?php

use Clue\React\Redis\SubscriptionApi;

class SubscriptionApiTest extends TestCase
{
    private $client;
    private $subscriptionApi;

    public function setUp()
    {
        $this->client = $this->getMock('Clue\React\Redis\Client');
        $this->subscriptionApi = new SubscriptionApi($this->client);
    }

    public function testSubscribe()
    {
        $promise = $this->subscriptionApi->subscribe('a');
        $this->expectPromiseReject($promise);

        $this->subscriptionApi->on('message', $this->expectCallableOnce());
    }
}
