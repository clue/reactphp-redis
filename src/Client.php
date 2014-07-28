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

class Client extends EventEmitter
{
    private $stream;
    private $parser;
    private $serializer;

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
                    $that->emit('message', array($data, $that));
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

    /**
     * Sends command with given $name and additial $args
     *
     * @param name $name
     * @param array $args
     */
    public function sendRequest($name, array $args = array())
    {
        $this->stream->write($this->serializer->getRequestMessage($name, $args));
    }

    /**
     * Sends given message model (request message)
     *
     * @param ModelInterface $message
     */
    public function sendMessage(ModelInterface $message)
    {
        $this->stream->write($message->getMessageSerialized($this->serializer));
    }

    /**
     * Immediately terminate the connection and discard incoming and outgoing buffers
     */
    public function close()
    {
        $this->stream->close();
    }
}
