# clue/redis-react [![Build Status](https://travis-ci.org/clue/php-redis-react.svg?branch=master)](https://travis-ci.org/clue/php-redis-react)

Async redis client implementation built on top of React PHP.

> Note: This project is in beta stage! Feel free to report any issues you encounter.

## Quickstart example

Once [installed](#install), you can use the following code to connect to your
local redis server and send some requests:

```php

$factory = new Factory($connector);
$factory->createClient()->then(function (Client $client) use ($loop) {
    $api = new ResponseApi($client);
    
    $api->set('greeting', 'Hello world');
    $api->append('greeting', '!');
    
    $api->get('greeting')->then(function ($greeting) {
        echo $greeting . PHP_EOL;
    });
    
    $api->incr('invocation')->then(function ($n) {
        echo 'count: ' . $n . PHP_EOL;
    });
    
    // end connection once all pending requests have been resolved
    $api->end();
});

$loop->run();
```

## Install

The recommended way to install this library is [through composer](http://getcomposer.org). [New to composer?](http://getcomposer.org/doc/00-intro.md)

```JSON
{
    "require": {
        "clue/redis-react": "0.3.*"
    }
}
```

## License

MIT

