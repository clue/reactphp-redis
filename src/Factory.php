<?php

namespace Clue\React\Redis;

use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use InvalidArgumentException;

class Factory
{
    private $loop;
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

        $this->loop = $loop;
        $this->connector = $connector;
        $this->protocol = $protocol;
    }

    /**
     * Create Redis client connected to address of given redis instance
     *
     * @param string $target Redis server URI to connect to
     * @return \React\Promise\PromiseInterface<Client> resolves with Client or rejects with \Exception
     */
    public function createClient($target)
    {
        try {
            $parts = $this->parseUrl($target);
        } catch (InvalidArgumentException $e) {
            return \React\Promise\reject($e);
        }

        $connecting = $this->connector->connect($parts['authority']);
        $deferred = new Deferred(function ($_, $reject) use ($connecting) {
            // connection cancelled, start with rejecting attempt, then clean up
            $reject(new \RuntimeException('Connection to Redis server cancelled'));

            // either close successful connection or cancel pending connection attempt
            $connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            $connecting->cancel();
        });

        $protocol = $this->protocol;
        $promise = $connecting->then(function (ConnectionInterface $stream) use ($protocol) {
            return new StreamingClient($stream, $protocol->createResponseParser(), $protocol->createSerializer());
        }, function (\Exception $e) {
            throw new \RuntimeException(
                'Connection to Redis server failed because underlying transport connection failed',
                0,
                $e
            );
        });

        if (isset($parts['auth'])) {
            $promise = $promise->then(function (StreamingClient $client) use ($parts) {
                return $client->auth($parts['auth'])->then(
                    function () use ($client) {
                        return $client;
                    },
                    function ($error) use ($client) {
                        $client->close();

                        throw new \RuntimeException(
                            'Connection to Redis server failed because AUTH command failed',
                            0,
                            $error
                        );
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

                        throw new \RuntimeException(
                            'Connection to Redis server failed because SELECT command failed',
                            0,
                            $error
                        );
                    }
                );
            });
        }

        $promise->then(array($deferred, 'resolve'), array($deferred, 'reject'));

        // use timeout from explicit ?timeout=x parameter or default to PHP's default_socket_timeout (60)
        $timeout = (float) isset($parts['timeout']) ? $parts['timeout'] : ini_get("default_socket_timeout");
        if ($timeout < 0) {
            return $deferred->promise();
        }

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout, $this->loop)->then(null, function ($e) {
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection to Redis server timed out after ' . $e->getTimeout() . ' seconds'
                );
            }
            throw $e;
        });
    }

    /**
     * Create Redis client connected to address of given redis instance
     *
     * @param string $target
     * @return Client
     */
    public function createLazyClient($target)
    {
        return new LazyClient($target, $this, $this->loop);
    }

    /**
     * @param string $target
     * @return array with keys authority, auth and db
     * @throws InvalidArgumentException
     */
    private function parseUrl($target)
    {
        $ret = array();
        // support `redis+unix://` scheme for Unix domain socket (UDS) paths
        if (preg_match('/^redis\+unix:\/\/([^:]*:[^@]*@)?(.+?)(\?.*)?$/', $target, $match)) {
            $ret['authority'] = 'unix://' . $match[2];
            $target = 'redis://' . (isset($match[1]) ? $match[1] : '') . 'localhost' . (isset($match[3]) ? $match[3] : '');
        }

        if (strpos($target, '://') === false) {
            $target = 'redis://' . $target;
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], array('redis', 'rediss'))) {
            throw new InvalidArgumentException('Given URL can not be parsed');
        }

        if (isset($parts['pass'])) {
            $ret['auth'] = rawurldecode($parts['pass']);
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            // skip first slash
            $ret['db'] = substr($parts['path'], 1);
        }

        if (!isset($ret['authority'])) {
            $ret['authority'] =
                ($parts['scheme'] === 'rediss' ? 'tls://' : '') .
                $parts['host'] . ':' .
                (isset($parts['port']) ? $parts['port'] : 6379);
        }

        if (isset($parts['query'])) {
            $args = array();
            parse_str($parts['query'], $args);

            if (isset($args['password'])) {
                $ret['auth'] = $args['password'];
            }

            if (isset($args['db'])) {
                $ret['db'] = $args['db'];
            }

            if (isset($args['timeout'])) {
                $ret['timeout'] = $args['timeout'];
            }
        }

        return $ret;
    }
}
