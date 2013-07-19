<?php

namespace Clue\Redis\React;

use Evenement\EventEmitter;
use React\Stream\Stream;
use Clue\Redis\Protocol\ProtocolInterface;
use Clue\Redis\Protocol\ParserException;
use Clue\Redis\Protocol\ErrorReplyException;
use React\Promise\Deferred;
use React\Promise\When;
use UnderflowException;
use RuntimeException;

class Client extends EventEmitter
{
    private $stream;
    private $protocol;
    private $deferreds = array();
    private $ending = false;

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
        if ($this->ending) {
            return When::reject(new RuntimeException('Connection closed'));
        }

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

        if ($this->ending && !$this->isBusy()) {
            $this->close();
        }
    }

    public function isBusy()
    {
        return !!$this->deferreds;
    }

    /**
     * end connection once all pending requests have been replied to
     *
     * @uses self::close() once all replies have been received
     * @see self::close() for closing the connection immediately
     */
    public function end()
    {
        $this->ending = true;

        if (!$this->isBusy()) {
            $this->close();
        }
    }

    public function close()
    {
        $this->ending = true;

        $this->stream->close();
    }
}
