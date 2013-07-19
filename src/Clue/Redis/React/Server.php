<?php

namespace Clue\Redis\React;

use Evenement\EventEmitter;
use React\Socket\Server as ServerSocket;
use React\EventLoop\LoopInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use React\Socket\Connection;

/**
 * Dummy redis server implementation
 *
 * @event connection(ConnectionInterface $connection, Server $thisServer)
 * @event request($requestData, ConnectionInterface $connection)
 */
class Server extends EventEmitter
{
    public function __construct(ServerSocket $socket, LoopInterface $loop)
    {
        $this->socket = $socket;
        $this->loop = $loop;

        $socket->on('connection', array($this, 'handleConnection'));
    }

    public function handleConnection(Connection $connection)
    {
        $protocol = ProtocolFactory::create();
        $that = $this;

        $connection->on('data', function ($data) use ($protocol, $that, $connection) {
            try {
                $protocol->pushIncoming($data);
            }
            catch (ParserException $e) {
                $connection->emit('error', $e);
                $connection->close();
                return;
            }
            while ($protocol->hasIncoming()) {
                $that->handleRequest($protocol->popIncoming(), $connection);
            }
        });

        $this->emit('connection', array($connection, $this));
    }

    public function handleRequest($data, Connection $connection)
    {
        $this->emit('request', array($data, $connection));

        $connection->write("-ERR Dummy redis server does not expose any methods\r\n");
    }

    public function close()
    {
        $this->socket->shutdown();
    }
}
