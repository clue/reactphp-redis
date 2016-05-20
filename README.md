# clue/redis-react [![Build Status](https://travis-ci.org/clue/php-redis-react.svg?branch=master)](https://travis-ci.org/clue/php-redis-react)

Async [Redis](http://redis.io/) client implementation built on top of [React PHP](http://reactphp.org/).

[Redis](http://redis.io/) is an open source, advanced, in-memory key-value database.
It offers a set of simple, atomic operations in order to work with its primitive data types.
Its lightweight design and fast operation makes it an ideal candidate for modern application stacks.
This library provides you a simple API to work with your Redis database from within PHP.
It enables you to set and query its data or use its PubSub topics to react to incoming events.

* **Async execution of Commands** -
  Send any number commands to  Redis in parallel (automatic pipeline) and
  process their responses as soon as results come in.
  The Promise-based design provides a *sane* interface to working with async responses.
* **Event-driven core** -
  Register your event handler callbacks to react to incoming events, such as an incoming PubSub message or a MONITOR event.
* **Lightweight, SOLID design** -
  Provides a thin abstraction that is [*just good enough*](http://en.wikipedia.org/wiki/Principle_of_good_enough)
  and does not get in your way.
  Future or custom commands and events require no changes to be supported.
* **Good test coverage** -
  Comes with an automated tests suite and is regularly tested against versions as old as Redis v2.6+

**Table of Contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Factory](#factory)
    * [createClient()](#createclient)
  * [Client](#client)
    * [Commands](#commands)
    * [Promises](#promises)
    * [on()](#on)
    * [close()](#close)
    * [end()](#end)
* [Install](#install)
* [License](#license)

## Quickstart example

Once [installed](#install), you can use the following code to connect to your
local Redis server and send some requests:

```php
$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$factory->createClient('localhost:6379')->then(function (Client $client) use ($loop) {
    $client->set('greeting', 'Hello world');
    $client->append('greeting', '!');
    
    $client->get('greeting')->then(function ($greeting) {
        // Hello world!
        echo $greeting . PHP_EOL;
    });
    
    $client->incr('invocation')->then(function ($n) {
        echo 'This is invocation #' . $n . PHP_EOL;
    });
    
    // end connection once all pending requests have been resolved
    $client->end();
});

$loop->run();
```

See also the [examples](examples).

## Usage

### Factory

The `Factory` is responsible for creating your [`Client`](#client) instance.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).

```php
$loop = \React\EventLoop\Factory::create();
$factory = new Factory($loop);
```

If you need custom DNS, proxy or TLS settings, you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket-client#connectorinterface):

```php
$factory = new Factory($loop, $connector);
```

#### createClient()

The `createClient($redisUri = null)` method can be used to create a new [`Client`](#client).
It helps with establishing a plain TCP/IP connection to Redis
and optionally authenticating (AUTH) and selecting the right database (SELECT).

```php
$factory->createClient('localhost:6379')->then(
    function (Client $client) {
        // client connected (and authenticated)
    },
    function (Exception $e) {
        // an error occured while trying to connect (or authenticate) client
    }
);
```

You can omit the complete URI if you want to connect to the default address `localhost:6379`:

```php
$factory->createClient();
```

You can omit the port if you're connecting to the default port 6379:

```php
$factory->createClient('localhost');
```

You can optionally include a password that will be used to authenticate (AUTH command) the client:

```php
$factory->createClient('auth@localhost');
```

You can optionally include a path that will be used to select (SELECT command) the right database:

```php
$factory->createClient('localhost/2');
```

### Client

The `Client` is responsible for exchanging messages with Redis
and keeps track of pending commands.

#### Commands

All [Redis commands](http://redis.io/commands) are automatically available as public methods like this:

```php
$client->get($key);
$client->set($key, $value);
$client->exists($key);
$client->expire($key, $seconds);
$client->mget($key1, $key2, $key3);

$client->multi();
$client->exec();

$client->publish($channel, $payload);
$client->subscribe($channel);

$client->ping();
$client->select($database);

// many more…
```

Listing all available commands is out of scope here, please refer to the [Redis command reference](http://redis.io/commands).
All [Redis commands](http://redis.io/commands) are automatically available as public methods via the magic `__call()` method.

Each of these commands supports async operation and either *resolves* with
its *results* or *rejects* with an `Exception`.
Please see the following section about [promises](#promises) for more details.

#### Promises

Sending commands is async (non-blocking), so you can actually send multiple commands in parallel.
Redis will respond to each command request with a response message, pending commands will be pipelined automatically.

Sending commands uses a [Promise](https://github.com/reactphp/promise)-based interface that makes it easy to react to when a command is *fulfilled*
(i.e. either successfully resolved or rejected with an error):

```php
$client->set('hello', 'world');
$client->get('hello')->then(function ($response) {
    // response received for GET command
    echo 'hello ' . $response;
});
```

#### on()

The `on($eventName, $eventHandler)` method can be used to register a new event handler.
Incoming events and errors will be forwarded to registered event handler callbacks:

```php
// global events:
$client->on('data', function (MessageInterface $message) {
    // process an incoming message (raw message object)
});
$client->on('close', function () {
    // the connection to Redis just closed
});
$client->on('error', function (Exception $e) {
    // and error has just been detected, the connection will terminate...
});

// pubsub events:
$client->on('message', function ($channel, $payload) {
    // pubsub message received on given $channel
});
$client->on('pmessage', function ($pattern, $channel, $payload) {
    // pubsub message received matching given $pattern
});
$client->on('subscribe', function ($channel, $total) {
    // subscribed to given $channel
});
$client->on('psubscribe', function ($pattern, $total) {
    // subscribed to matching given $pattern
});
$client->on('unsubscribe', function ($channel, $total) {
    // unsubscribed from given $channel
});
$client->on('punsubscribe', function ($pattern, $total) {
    // unsubscribed from matching given $pattern
});

// monitor events:
$client->on('monitor', function (StatusReply $message) {
    // somebody executed a command
});
```

#### close()

The `close()` method can be used to force-close the Redis connection and reject all pending commands.

#### end()

The `end()` method can be used to soft-close the Redis connection once all pending commands are completed.

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/redis-react:^0.5
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

## License

MIT
