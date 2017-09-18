<?php

namespace Clue\React\Redis;

use Clue\React\Redis\StreamingClient;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use InvalidArgumentException;

class Factory
{
    private $connector;
    private $protocol;

    /**
     * @param LoopInterface $loop
     * @param ConnectorInterface|\React\SocketClient\ConnectorInterface|null $connector
     *     [optional] Connector to use. Should be `null` in order to use default
     *     Connector. Passing a `\React\SocketClient\ConnectorInterface` is
     *     deprecated and only supported for BC reasons and will be removed in
     *     future versions.
     * @param ProtocolFactory|null $protocol
     */
    public function __construct(LoopInterface $loop, $connector = null, ProtocolFactory $protocol = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        } elseif (!$connector instanceof ConnectorInterface) {
            $connector = new ConnectorUpcaster($connector);
        }

        if ($protocol === null) {
            $protocol = new ProtocolFactory();
        }

        $this->connector = $connector;
        $this->protocol = $protocol;
    }

    /**
     * create redis client connected to address of given redis instance
     *
     * @param string|null $target
     * @return \React\Promise\PromiseInterface resolves with Client or rejects with \Exception
     */
    public function createClient($target = null)
    {
        try {
            $parts = $this->parseUrl($target);
        } catch (InvalidArgumentException $e) {
            return Promise\reject($e);
        }

        $protocol = $this->protocol;

        $promise = $this->connector->connect($parts['host'] . ':' . $parts['port'])->then(function (ConnectionInterface $stream) use ($protocol) {
            return new StreamingClient($stream, $protocol->createResponseParser(), $protocol->createSerializer());
        });

        if (isset($parts['auth'])) {
            $promise = $promise->then(function (StreamingClient $client) use ($parts) {
                return $client->auth($parts['auth'])->then(
                    function () use ($client) {
                        return $client;
                    },
                    function ($error) use ($client) {
                        $client->close();
                        throw $error;
                    }
                );
            });
        }

        if (isset($parts['db'])) {
            $promise = $promise->then(function (StreamingClient $client) use ($parts) {
                return $client->select($parts['db'])->then(
                    function () use ($client) {
                        return $client;
                    },
                    function ($error) use ($client) {
                        $client->close();
                        throw $error;
                    }
                );
            });
        }

        return $promise;
    }

    /**
     * @param string|null $target
     * @return array with keys host, port, auth and db
     * @throws InvalidArgumentException
     */
    private function parseUrl($target)
    {
        if ($target === null) {
            $target = 'tcp://127.0.0.1';
        }
        if (strpos($target, '://') === false) {
            $target = 'tcp://' . $target;
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['host']) || $parts['scheme'] !== 'tcp') {
            throw new InvalidArgumentException('Given URL can not be parsed');
        }

        if (!isset($parts['port'])) {
            $parts['port'] = 6379;
        }

        if ($parts['host'] === 'localhost') {
            $parts['host'] = '127.0.0.1';
        }

        $auth = null;
        if (isset($parts['user'])) {
            $auth = $parts['user'];
        }
        if (isset($parts['pass'])) {
            $auth .= ':' . $parts['pass'];
        }
        if ($auth !== null) {
            $parts['auth'] = $auth;
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            // skip first slash
            $parts['db'] = substr($parts['path'], 1);
        }

        unset($parts['scheme'], $parts['user'], $parts['pass'], $parts['path']);

        return $parts;
    }
}
