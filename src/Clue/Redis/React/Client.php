<?php

namespace Clue\Redis\React;

use Evenement\EventEmitter;
use React\Stream\Stream;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Model\ErrorReplyException;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use Clue\Redis\React\Request;
use React\Promise\When;
use UnderflowException;
use RuntimeException;

class Client extends EventEmitter
{
    private $stream;
    private $parser;
    private $serializer;
    private $requests = array();
    private $ending = false;

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
        $this->parser = $parser;
        $this->serializer = $serializer;
    }

    public function __call($name, $args)
    {
        if ($this->ending) {
            return When::reject(new RuntimeException('Connection closed'));
        }

        $this->stream->write($this->serializer->getRequestMessage($name, $args));

        $request = new Request($name);
        $this->requests []= $request;

        return $request->promise();
    }

    public function handleReply($data)
    {
        $this->emit('message', array($data, $this));

        if (!$this->requests) {
            throw new UnderflowException('Unexpected reply received, no matching request found');
        }

        $request = array_shift($this->requests);
        /* @var $request Request */

        $request->handleReply($data);

        if ($this->ending && !$this->isBusy()) {
            $this->close();
        }
    }

    public function isBusy()
    {
        return !!$this->requests;
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

        // reject all remaining requests in the queue
        while($this->requests) {
            $request = array_shift($this->requests);
            /* @var $request Request */
            $request->reject(new RuntimeException('Connection closing'));
        }
    }
}
