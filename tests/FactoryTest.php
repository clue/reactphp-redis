<?php

use Clue\Redis\React\Client;

use Clue\Redis\React\Factory;

class FactoryTest extends TestCase
{
    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $factory = new React\Dns\Resolver\Factory();
        $resolver = $factory->create('6.6.6.6', $this->loop);
        $connector = new React\SocketClient\Connector($this->loop, $resolver);

        $this->factory = new Factory($this->loop, $connector);
    }

    public function testClientDefaultSuccess()
    {
        $promise = $this->factory->createClient();

        $this->expectPromiseResolve($promise)->then(function (Client $client) {
            $client->end();
        });

        $this->loop->run();
    }

    public function testClientUnconnectableAddress()
    {
        $promise = $this->factory->createClient('tcp://127.0.0.1:2');

        $this->expectPromiseReject($promise);

        $this->loop->tick();
    }

    public function testClientInvalidAddress()
    {
        $promise = $this->factory->createClient('invalid target');

        $this->expectPromiseReject($promise);
    }
}
