<?php

namespace Clue\React\Redis;

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;

/**
 * Simple interface for executing redis commands
 *
 * @method error(Exception $error)
 *
 * @method publish($channel, $message)
 * @method subscribe($channel)
 * @method unsubscribe($channel)
 *
 * @method pmessage($pattern, $channel, $message)
 * @method psubscribe($channel)
 * @method punsubscribe($channel)
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
     * @return PromiseInterface Promise<mixed,Exception>
     */
    public function __call($name, $args);

    /**
     * end connection once all pending requests have been replied to
     *
     * @return void
     * @uses self::close() once all replies have been received
     * @see self::close() for closing the connection immediately
     */
    public function end();

    /**
     * close connection immediately
     *
     * This will emit the "close" event.
     *
     * @return void
     * @see self::end() for closing the connection once the client is idle
     */
    public function close();
}
