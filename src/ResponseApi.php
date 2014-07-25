<?php

namespace Clue\React\Redis;

use Clue\Redis\Protocol\Model\ModelInterface;
use UnderflowException;
use RuntimeException;
use React\Promise\Deferred;
use Clue\Redis\Protocol\Model\ErrorReply;

class ResponseApi
{
    private $client;
    private $requests = array();
    private $ending = false;

    public function __construct(Client $client)
    {
        $this->client = $client;

        $client->on('message', array($this, 'handleMessage'));
        $client->on('close', array($this, 'handleClose'));
    }

    public function __call($name, $args)
    {
        $request = new Deferred();

        if ($this->ending) {
            $request->reject(new RuntimeException('Connection closed'));
        } else {
            $this->client->send($name, $args);
            $this->requests []= $request;
        }

        return $request->promise();
    }

    public function handleMessage(ModelInterface $message)
    {
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
            $this->client->close();
        }
    }

    public function handleClose()
    {
        $this->ending = true;

        $this->client->removeListener('message', array($this, 'handleMessage'));
        $this->client->removeListener('close', array($this, 'handleClose'));

        // reject all remaining requests in the queue
        while($this->requests) {
            $request = array_shift($this->requests);
            /* @var $request Request */
            $request->reject(new RuntimeException('Connection closing'));
        }

        $this->requests = array();
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
            $this->client->close();
        }
    }
}
