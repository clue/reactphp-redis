<?php

namespace Clue\React\Redis;

use Evenement\EventEmitter;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Clue\Redis\Protocol\Factory as ProtocolFactory;
use UnderflowException;
use RuntimeException;
use InvalidArgumentException;
use React\Promise\Deferred;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use React\Stream\DuplexStreamInterface;

/**
 * @internal
 */
class StreamingClient extends EventEmitter implements Client
{
    private $stream;
    private $parser;
    private $serializer;
    private $requests = array();
    private $ending = false;
    private $closed = false;

    private $subscribed = 0;
    private $psubscribed = 0;

    public function __construct(DuplexStreamInterface $stream, ParserInterface $parser = null, SerializerInterface $serializer = null)
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

        $this->stream = $stream;
        $this->parser = $parser;
        $this->serializer = $serializer;
    }

    public function __call($name, $args)
    {
        $request = new Deferred();
        $promise = $request->promise();

        $name = strtolower($name);

        // special (p)(un)subscribe commands only accept a single parameter and have custom response logic applied
        static $pubsubs = array('subscribe', 'unsubscribe', 'psubscribe', 'punsubscribe');

        if ($this->ending) {
            $request->reject(new RuntimeException('Connection closed'));
        } elseif (count($args) !== 1 && in_array($name, $pubsubs)) {
            $request->reject(new InvalidArgumentException('PubSub commands limited to single argument'));
        } elseif ($name === 'monitor') {
            $request->reject(new \BadMethodCallException('MONITOR command explicitly not supported'));
        } else {
            $this->stream->write($this->serializer->getRequestMessage($name, $args));
            $this->requests []= $request;
        }

        if (in_array($name, $pubsubs)) {
            $that = $this;
            $subscribed =& $this->subscribed;
            $psubscribed =& $this->psubscribed;

            $promise->then(function ($array) use ($that, &$subscribed, &$psubscribed) {
                $first = array_shift($array);

                // (p)(un)subscribe messages are to be forwarded
                $that->emit($first, $array);

                // remember number of (p)subscribe topics
                if ($first === 'subscribe' || $first === 'unsubscribe') {
                    $subscribed = $array[1];
                } else {
                    $psubscribed = $array[1];
                }
            });
        }

        return $promise;
    }

    public function handleMessage(ModelInterface $message)
    {
        if (($this->subscribed !== 0 || $this->psubscribed !== 0) && $message instanceof MultiBulkReply) {
            $array = $message->getValueNative();
            $first = array_shift($array);

            // pub/sub messages are to be forwarded and should not be processed as request responses
            if (in_array($first, array('message', 'pmessage'))) {
                $this->emit($first, $array);
                return;
            }
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

        if ($this->ending && !$this->requests) {
            $this->close();
        }
    }

    public function end()
    {
        $this->ending = true;

        if (!$this->requests) {
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
