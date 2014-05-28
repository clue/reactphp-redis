<?php

namespace Clue\React\Redis;

use Clue\Redis\Protocol\Model\ModelInterface;
use Clue\Redis\Protocol\Model\ErrorReply;
use React\Promise\Deferred;

class Request extends Deferred
{
    public function handleReply(ModelInterface $data)
    {
        if ($data instanceof ErrorReply) {
            $this->reject($data);
        } else {
            $this->resolve($data->getValueNative());
        }
    }
}
