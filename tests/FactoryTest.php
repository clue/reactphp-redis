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
        $promise = $this->factory->createClient('invalid target');

        $this->expectPromiseReject($promise);
    }

    public function testServerSuccess()
    {
        $promise = $this->factory->createServer('tcp://localhost:1337');

        $this->expectPromiseResolve($promise)->then(function (Server $server) {
            $server->close();
        });
    }

    public function testClientRequiresConnector()
    {
        $factory = new Factory($this->loop);

        $this->expectPromiseReject($factory->createClient());
    }

    public function testPairAuthRejectDisconnects()
    {
        $server = null;
        $done = false;

        // start a server that only sends ERR messages.
        $this->factory->createServer('tcp://localhost:1337')->then(function (Server $s) use (&$server) {
            fwrite(STDOUT, '1. server: created' . PHP_EOL);
            $server = $s;
        });

        $this->assertNotNull($server);

        // we expect a single single client
        $server->on('connection', $this->expectCallableOnce());

        $once = $this->expectCallableOnce();
        $server->on('connection', function(ConnectionInterface $connection) use ($once, &$done, $server) {
            // we expect the client to close the connection once he receives an ERR messages.
            $connection->on('close', $once);

            fwrite(STDOUT, '2. server: incomming connection' . PHP_EOL);

            // end the loop (stop ticking)
            $connection->on('close', function() use (&$done, $server) {
                fwrite(STDOUT, '4. server: connection closed' . PHP_EOL);
                $done = true;
                $server->close();
            });
        });

        // we expect the factory to fail because of the ERR message.
        $promise = $this->expectPromiseReject($this->factory->createClient('tcp://auth@127.0.0.1:1337'));
        $promise->then(null, function() {
            fwrite(STDOUT, '3. client: creating failed' . PHP_EOL);
        });

        $this->loop->run();

        fwrite(STDOUT, '5. done' . PHP_EOL);
    }

    public function testServerAddressInvalidFail()
    {
        $promise = $this->factory->createServer('invalid address');

        $this->expectPromiseReject($promise);
    }

    public function testServerAddressInUseFail()
    {
        $promise = $this->factory->createServer('tcp://localhost:6379');

        $this->expectPromiseReject($promise);
    }
}
