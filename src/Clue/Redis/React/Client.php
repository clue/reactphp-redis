<?php

namespace Clue\Redis\React;

use Evenement\EventEmitter;
use React\Stream\Stream;
use Clue\Redis\Protocol\ProtocolInterface;
use Clue\Redis\Protocol\ParserException;
use Clue\Redis\Protocol\ErrorReplyException;
use React\Promise\Deferred;
use UnderflowException;

class Client extends EventEmitter
{
    private $stream;
    private $protocol;
    private $deferreds = array();

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
                $data = $protocol->popIncoming();

                try {
                    $that->handleReply($data);
                }
                catch (UnderflowException $error) {
                    $that->emit('error', array($error));
                    $that->close();
                    return;
                }
            }
        });
        $stream->on('close', function () use ($that) {
            $that->close();
            $that->emit('close');
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

        $deferred = new Deferred();
        $this->deferreds []= $deferred;
        return $deferred->promise();
    }

    public function handleReply($data)
    {
        $this->emit('message', array($data, $this));

        if (!$this->deferreds) {
            throw new UnderflowException('Unexpected reply received, no matching request found');
        }

        $deferred = array_shift($this->deferreds);
        /* @var $deferred Deferred */

        if ($data instanceof ErrorReplyException) {
            $deferred->reject($data);
        } else {
            $deferred->resolve($data);
        }
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
