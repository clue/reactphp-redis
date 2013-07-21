<?php

namespace Clue\Redis\React;

use Clue\Redis\Protocol\ErrorReplyException;
use React\Promise\Deferred;

class Request extends Deferred
{
    public function handleReply($data)
    {
        if ($data instanceof ErrorReplyException) {
            $this->reject($data);
        } else {
            $this->resolve($data);
        }
    }
}
