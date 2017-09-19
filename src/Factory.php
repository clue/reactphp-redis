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
     * @param ConnectorInterface|null $connector [optional] Connector to use.
     *     Should be `null` in order to use default Connector.
     * @param ProtocolFactory|null $protocol
     */
    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null, ProtocolFactory $protocol = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
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
     * @param string $target Redis server URI to connect to
     * @return \React\Promise\PromiseInterface resolves with Client or rejects with \Exception
     */
    public function createClient($target)
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
     * @param string $target
     * @return array with keys host, port, auth and db
     * @throws InvalidArgumentException
     */
    private function parseUrl($target)
    {
        if (strpos($target, '://') === false) {
            $target = 'redis://' . $target;
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('redis', 'rediss'))) {
            throw new InvalidArgumentException('Given URL can not be parsed');
        }

        if (!isset($parts['port'])) {
            $parts['port'] = 6379;
        }

        if ($parts['host'] === 'localhost') {
            $parts['host'] = '127.0.0.1';
        }

        if (isset($parts['pass'])) {
            $parts['auth'] = rawurldecode($parts['pass']);
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            // skip first slash
            $parts['db'] = substr($parts['path'], 1);
        }

        if ($parts['scheme'] === 'rediss') {
            $parts['host'] = 'tls://' . $parts['host'];
        }

        if (isset($parts['query'])) {
            $args = array();
            parse_str($parts['query'], $args);

            if (isset($args['password'])) {
                $parts['auth'] = $args['password'];
            }

            if (isset($args['db'])) {
                $parts['db'] = $args['db'];
            }
        }

        unset($parts['scheme'], $parts['user'], $parts['pass'], $parts['path']);

        return $parts;
    }
}
