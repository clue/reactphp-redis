<?php

namespace Clue\React\Redis\Io;

use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\MultiBulkReply;
use Clue\Redis\Protocol\Parser\ParserException;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Evenement\EventEmitter;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\DuplexStreamInterface;

/**
 * @internal
 */
class StreamingClient extends EventEmitter
{
    /** @var DuplexStreamInterface */
    private $stream;

    /** @var SerializerInterface */
    private $serializer;

    /** @var Deferred<mixed>[] */
    private $requests = [];

    /** @var bool */
    private $ending = false;

    /** @var bool */
    private $closed = false;

    /** @var int */
    private $subscribed = 0;

    /** @var int */
    private $psubscribed = 0;

    public function __construct(DuplexStreamInterface $stream, ParserInterface $parser, SerializerInterface $serializer)
    {
        $stream->on('data', function (string $chunk) use ($parser) {
            try {
                $models = $parser->pushIncoming($chunk);
            } catch (ParserException $error) {
                $this->emit('error', [new \UnexpectedValueException(
                    'Invalid data received: ' . $error->getMessage() . ' (EBADMSG)',
                    defined('SOCKET_EBADMSG') ? SOCKET_EBADMSG : 77,
                    $error
                )]);
                $this->close();
                return;
            }

            foreach ($models as $data) {
                try {
                    $this->handleMessage($data);
                } catch (\UnderflowException $error) {
                    $this->emit('error', [$error]);
                    $this->close();
                    return;
                }
            }
        });

        $stream->on('close', [$this, 'close']);

        $this->stream = $stream;
        $this->serializer = $serializer;
    }

    /**
     * @param string[] $args
     * @return PromiseInterface<mixed>
     */
    public function __call(string $name, array $args): PromiseInterface
    {
        $request = new Deferred();
        $promise = $request->promise();

        $name = strtolower($name);

        // special (p)(un)subscribe commands only accept a single parameter and have custom response logic applied
        static $pubsubs = ['subscribe', 'unsubscribe', 'psubscribe', 'punsubscribe'];

        if ($this->ending) {
            $request->reject(new \RuntimeException(
                'Connection ' . ($this->closed ? 'closed' : 'closing'). ' (ENOTCONN)',
                defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107
            ));
        } elseif (count($args) !== 1 && in_array($name, $pubsubs)) {
            $request->reject(new \InvalidArgumentException(
                'PubSub commands limited to single argument (EINVAL)',
                defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
            ));
        } elseif ($name === 'monitor') {
            $request->reject(new \BadMethodCallException(
                'MONITOR command explicitly not supported (ENOTSUP)',
                defined('SOCKET_ENOTSUP') ? SOCKET_ENOTSUP : (defined('SOCKET_EOPNOTSUPP') ? SOCKET_EOPNOTSUPP : 95)
            ));
        } else {
            $this->stream->write($this->serializer->getRequestMessage($name, $args));
            $this->requests []= $request;
        }

        if (in_array($name, $pubsubs)) {
            $promise->then(function (array $array) {
                $first = array_shift($array);

                // (p)(un)subscribe messages are to be forwarded
                $this->emit($first, $array);

                // remember number of (p)subscribe topics
                if ($first === 'subscribe' || $first === 'unsubscribe') {
                    $this->subscribed = $array[1];
                } else {
                    $this->psubscribed = $array[1];
                }
            });
        }

        return $promise;
    }

    public function handleMessage(ModelInterface $message): void
    {
        if (($this->subscribed !== 0 || $this->psubscribed !== 0) && $message instanceof MultiBulkReply) {
            $array = $message->getValueNative();
            assert(\is_array($array));
            $first = array_shift($array);

            // pub/sub messages are to be forwarded and should not be processed as request responses
            if (in_array($first, ['message', 'pmessage'])) {
                $this->emit($first, $array);
                return;
            }
        }

        if (!$this->requests) {
            throw new \UnderflowException(
                'Unexpected reply received, no matching request found (ENOMSG)',
                defined('SOCKET_ENOMSG') ? SOCKET_ENOMSG : 42
            );
        }

        $request = array_shift($this->requests);
        assert($request instanceof Deferred);

        if ($message instanceof ErrorReply) {
            $request->reject($message);
        } else {
            $request->resolve($message->getValueNative());
        }

        if ($this->ending && !$this->requests) {
            $this->close();
        }
    }

    public function end(): void
    {
        $this->ending = true;

        if (!$this->requests) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->ending = true;
        $this->closed = true;

        $remoteClosed = $this->stream->isReadable() === false && $this->stream->isWritable() === false;
        $this->stream->close();

        $this->emit('close');

        // reject all remaining requests in the queue
        while ($this->requests) {
            $request = array_shift($this->requests);
            assert($request instanceof Deferred);

            if ($remoteClosed) {
                $request->reject(new \RuntimeException(
                    'Connection closed by peer (ECONNRESET)',
                    defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104
                ));
            } else {
                $request->reject(new \RuntimeException(
                    'Connection closing (ECONNABORTED)',
                    defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
                ));
            }
        }
    }
}
