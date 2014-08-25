<?php

namespace Clue\React\Redis;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;
use Clue\Redis\Protocol\Model\ModelInterface;

/**
 * Simple interface for executing redis commands
 *
 * @event message(ModelInterface $model, Client $thisClient)
 */
interface Client extends EventEmitterInterface
{
    /**
     * Invoke the given command and return a Promise that will be resolved when the request has been replied to
     *
     * This is a magic method that will be invoked when calling any redis
     * command on this instance.
     *
     * @param string   $name
     * @param string[] $args
     * @return PromiseInterface
     */
    public function __call($name, $args);

    /**
     * Checks if the client is busy, i.e. still has any requests pending
     *
     * @return boolean
     */
    public function isBusy();

    /**
     * end connection once all pending requests have been replied to
     *
     * @uses self::close() once all replies have been received
     * @see self::close() for closing the connection immediately
     */
    public function end();

    /**
     * close connection immediately
     *
     * @see self::end() for closing the connection once the client is idle
     */
    public function close();
}
