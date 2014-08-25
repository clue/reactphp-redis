# clue/redis-react [![Build Status](https://travis-ci.org/clue/php-redis-react.svg?branch=master)](https://travis-ci.org/clue/php-redis-react)

Async redis client implementation built on top of React PHP.

> Note: This project is in beta stage! Feel free to report any issues you encounter.

## Quickstart example

Once [installed](#install), you can use the following code to connect to your
local redis server and send some requests:

```php
$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$factory->createClient()->then(function (Client $client) use ($loop) {
    $client->SET('greeting', 'Hello world');
    $client->APPEND('greeting', '!');
    
    $client->GET('greeting')->then(function ($greeting) {
        echo $greeting . PHP_EOL;
    });
    
    $client->INCR('invocation')->then(function ($n) {
        echo 'count: ' . $n . PHP_EOL;
    });
    
    // end connection once all pending requests have been resolved
    $client->end();
});

$loop->run();
```

See also the [examples](examples).

## Install

The recommended way to install this library is [through composer](http://getcomposer.org). [New to composer?](http://getcomposer.org/doc/00-intro.md)

```JSON
{
    "require": {
        "clue/redis-react": "~0.4.0"
    }
}
```

## License

MIT

