<?php

namespace Clue\React\Redis;

use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use Clue\React\Redis\StreamingClient;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\SocketClient\Connector;
use React\Dns\Resolver\Factory as ResolverFactory;
use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Promise;

class Factory
{
    private $connector;
    private $protocol;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null, ProtocolFactory $protocol = null)
    {
        if ($connector === null) {
            $resolverFactory = new ResolverFactory();
            $connector = new Connector($loop, $resolverFactory->create('8.8.8.8', $loop));
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

        $promise = $this->connector->create($parts['host'], $parts['port'])->then(function (Stream $stream) use ($protocol) {
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
        $validSchemes = array('redis', 'tcp');
        if ($parts === false || !isset($parts['host']) || !in_array($parts['scheme'], $validSchemes)) {
            throw new InvalidArgumentException('Given URL can not be parsed');
        }

        if (!isset($parts['port'])) {
            $parts['port'] = 6379;
        }

        if ($parts['host'] === 'localhost') {
            $parts['host'] = '127.0.0.1';
        }

        // username:password@ (Redis doesn't support usernames)
        if (isset($parts['pass'])) {
			$parts['auth'] = $parts['pass'];
		}
		// password@
		else if (isset($parts['user'])) {
			$parts['auth'] = $parts['user'];
		}

        if (isset($parts['path']) && $parts['path'] !== '') {
            // skip first slash
            $parts['db'] = substr($parts['path'], 1);
        }

        unset($parts['scheme'], $parts['user'], $parts['pass'], $parts['path']);

        return $parts;
    }
}
