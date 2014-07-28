<?php

use Clue\React\Redis\SubscriptionApi;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\BulkReply;
use Clue\Redis\Protocol\Model\IntegerReply;

class SubscriptionApiTest extends TestCase
{
    private $client;
    private $subscriptionApi;

    public function setUp()
    {
        $this->client = $this->getMockBuilder('Clue\React\Redis\Client')->disableOriginalConstructor()->setMethods(array('sendRequest', 'close'))->getMock();
        $this->subscriptionApi = new SubscriptionApi($this->client);
    }

    public function testSubscribe()
    {
        $promise = $this->subscriptionApi->subscribe('a');

        $this->expectPromiseResolve($promise);
        $this->pretendMessage(new MultiBulkReply(array(new BulkReply('subscribe'), new BulkReply('a'), new IntegerReply(1))));

        //$this->subscriptionApi->on('message', $this->expectCallableOnce());
        //$this->pretendMessage(new MultiBulkReply(array(new BulkReply('message'), new BulkReply('a'), new BulkReply('data'))));
    }

    private function pretendMessage(ModelInterface $model)
    {
        $this->client->emit('message', array($model, $this->client));
    }
}
