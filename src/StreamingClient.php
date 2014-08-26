<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use React\Stream\Stream;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\ErrorReplyException;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use UnderflowException;
use RuntimeException;
use React\Promise\Deferred;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\StatusReply;

class StreamingClient extends EventEmitter implements Client
{
    private $stream;
    private $parser;
    private $serializer;
    private $requests = array();
    private $ending = false;
    private $closed = false;

    private $monitoring = false;

    public function __construct(Stream $stream, ParserInterface $parser = null, SerializerInterface $serializer = null)
    {
        if ($parser === null || $serializer === null) {
            $factory = new ProtocolFactory();
            if ($parser === null) {
                $parser = $factory->createResponseParser();
            }
            if ($serializer === null) {
                $serializer = $factory->createSerializer();
            }
        }

        $that = $this;
        $stream->on('data', function($chunk) use ($parser, $that) {
            try {
                $models = $parser->pushIncoming($chunk);
            }
            catch (ParserException $error) {
                $that->emit('error', array($error));
                $that->close();
                return;
            }

            foreach ($models as $data) {
                try {
                    $that->handleMessage($data);
                }
                catch (UnderflowException $error) {
                    $that->emit('error', array($error));
                    $that->close();
                    return;
                }
            }
        });

        $stream->on('close', array($this, 'close'));
        $stream->resume();

        $this->stream = $stream;
        $this->parser = $parser;
        $this->serializer = $serializer;
    }

    public function __call($name, $args)
    {
        $request = new Deferred();

        if ($this->ending) {
            $request->reject(new RuntimeException('Connection closed'));
        } else {
            $this->stream->write($this->serializer->getRequestMessage($name, $args));
            $this->requests []= $request;
        }

        if (strtolower($name) === 'monitor') {
            $monitoring =& $this->monitoring;
            $request->then(function () use (&$monitoring) {
                $monitoring = true;
            });
        }

        return $request->promise();
    }

    public function handleMessage(ModelInterface $message)
    {
        $this->emit('data', array($message, $this));

        if ($this->monitoring && $message instanceof StatusReply) {
            $this->emit('monitor', array($message, $this));
            return;
        }

        if (!$this->requests) {
            throw new UnderflowException('Unexpected reply received, no matching request found');
        }

        $request = array_shift($this->requests);
        /* @var $request Deferred */

        if ($message instanceof ErrorReply) {
            $request->reject($message);
        } else {
            $request->resolve($message->getValueNative());
        }

        if ($this->ending && !$this->isBusy()) {
            $this->close();
        }
    }

    public function isBusy()
    {
        return !!$this->requests;
    }

    public function end()
    {
        $this->ending = true;

        if (!$this->isBusy()) {
            $this->close();
        }
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->ending = true;
        $this->closed = true;

        $this->stream->close();

        $this->emit('close');

        // reject all remaining requests in the queue
        while($this->requests) {
            $request = array_shift($this->requests);
            /* @var $request Request */
            $request->reject(new RuntimeException('Connection closing'));
        }
    }
}
