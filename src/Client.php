<?php

namespace Clue\React\Redis;

use Evenement\EventEmitterInterface;

interface Client extends EventEmitterInterface
{
    public function __call($name, $args);

    public function isBusy();

    /**
     * end connection once all pending requests have been replied to
     *
     * @uses self::close() once all replies have been received
     * @see self::close() for closing the connection immediately
     */
    public function end();

    public function close();
}
