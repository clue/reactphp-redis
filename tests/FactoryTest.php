<?php

use React\Socket\ConnectionInterface;

use Clue\Redis\React\Server;

use Clue\Redis\React\Client;

use Clue\Redis\React\Factory;

class FactoryTest extends TestCase
{
    public function setUp()
    {
        $this->loop = new React\EventLoop\StreamSelectLoop();
        $factory = new React\Dns\Resolver\Factory();
        $resolver = $factory->create('6.6.6.6', $this->loop);
        $connector = new React\SocketClient\Connector($this->loop, $resolver);

        $this->factory = new Factory($this->loop, $connector);
    }

    public function testPrequisiteServerAcceptsAnyPassword()
    {
        $this->markTestSkipped();
    }

    /**
     * @depends testPrequisiteServerAcceptsAnyPassword
     */
    public function testClientDefaultSuccess()
    {
        $promise = $this->factory->createClient();

        $this->expectPromiseResolve($promise)->then(function (Client $client) {
            $client->end();
        });

        $this->loop->run();
    }

    /**
     * @depends testPrequisiteServerAcceptsAnyPassword
     */
    public function testClientAuthSelect()
    {
        $promise = $this->factory->createClient('tcp://authenticationpassword@127.0.0.1:6379/0');

        $this->expectPromiseResolve($promise)->then(function (Client $client) {
            $client->end();
        });

        $this->loop->run();
    }

    /**
     * @depends testPrequisiteServerAcceptsAnyPassword
     */
    public function testClientAuthenticationContainsColons()
    {
        $promise = $this->factory->createClient('tcp://authentication:can:contain:colons@127.0.0.1:6379');

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
        $promise = $this->factory->createClient('http://invalid target');

        $this->expectPromiseReject($promise);
    }

    public function testClientRequiresConnector()
    {
        $factory = new Factory($this->loop);

        $this->expectPromiseReject($factory->createClient());
    }
}
