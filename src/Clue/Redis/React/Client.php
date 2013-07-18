<?php

namespace Clue\Redis\React;

use Evenement\EventEmitter;
use React\Stream\Stream;
use Clue\Redis\Protocol\ProtocolInterface;
use Clue\Redis\Protocol\ParserException;
use React\Promise\Deferred;

class Client extends EventEmitter
{
    private $stream;
    private $protocol;

    public function __construct(Stream $stream, ProtocolInterface $protocol)
    {
        $that = $this;
        $stream->on('data', function($chunk) use ($protocol, $that) {
            try {
                $protocol->pushIncoming($chunk);
            }
            catch (ParserException $error) {
                $that->emit('error', array($error));
                $that->close();
                return;
            }

            while ($protocol->hasIncoming()) {
                $that->emit('message', array($protocol->popIncoming(), $that));
            }
        });
        $stream->on('close', function () use ($that) {
            $that->close();
        });
        $stream->resume();
        $this->stream = $stream;
        $this->protocol = $protocol;
    }

    public function __call($name, $args)
    {
        /* Build the Redis unified protocol command */
        array_unshift($args, strtoupper($name));

        $this->stream->write($this->protocol->createMessage($args));

        return new Deferred();
    }

    public function end()
    {
        $this->stream->end();
    }

    public function close()
    {
        $this->stream->close();
    }
}
