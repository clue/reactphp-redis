# clue/reactphp-redis

[![CI status](https://github.com/clue/reactphp-redis/actions/workflows/ci.yml/badge.svg)](https://github.com/clue/reactphp-redis/actions)
[![code coverage](https://img.shields.io/badge/code%20coverage-100%25-success)](#tests)
[![PHPStan level](https://img.shields.io/badge/PHPStan%20level-max-success)](#tests)
[![installs on Packagist](https://img.shields.io/packagist/dt/clue/redis-react?color=blue&label=installs%20on%20Packagist)](https://packagist.org/packages/clue/redis-react)

Async [Redis](https://redis.io/) client implementation, built on top of [ReactPHP](https://reactphp.org/).

> **Development version:** This branch contains the code for the upcoming 3.0 release.
> For the code of the current stable 2.x release, check out the
> [`2.x` branch](https://github.com/clue/reactphp-redis/tree/2.x).
>
> The upcoming 3.0 release will be the way forward for this package.
> However, we will still actively support 2.x for those not yet
> on the latest version.
> See also [installation instructions](#install) for more details.

[Redis](https://redis.io/) is an open source, advanced, in-memory key-value database.
It offers a set of simple, atomic operations in order to work with its primitive data types.
Its lightweight design and fast operation makes it an ideal candidate for modern application stacks.
This library provides you a simple API to work with your Redis database from within PHP.
It enables you to set and query its data or use its PubSub topics to react to incoming events.

* **Async execution of Commands** -
  Send any number of commands to Redis in parallel (automatic pipeline) and
  process their responses as soon as results come in.
  The Promise-based design provides a *sane* interface to working with async responses.
* **Event-driven core** -
  Register your event handler callbacks to react to incoming events, such as an incoming PubSub message event.
* **Lightweight, SOLID design** -
  Provides a thin abstraction that is [*just good enough*](https://en.wikipedia.org/wiki/Principle_of_good_enough)
  and does not get in your way.
  Future or custom commands and events require no changes to be supported.
* **Good test coverage** -
  Comes with an automated tests suite and is regularly tested against versions as old as Redis v2.6 and newer.

**Table of Contents**

* [Support us](#support-us)
* [Quickstart example](#quickstart-example)
* [Usage](#usage)
    * [Commands](#commands)
    * [Promises](#promises)
    * [PubSub](#pubsub)
* [API](#api)
    * [RedisClient](#redisclient)
        * [__construct()](#__construct)
        * [__call()](#__call)
        * [end()](#end)
        * [close()](#close)
        * [error event](#error-event)
        * [close event](#close-event)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Support us

We invest a lot of time developing, maintaining and updating our awesome
open-source projects. You can help us sustain this high-quality of our work by
[becoming a sponsor on GitHub](https://github.com/sponsors/clue). Sponsors get
numerous benefits in return, see our [sponsoring page](https://github.com/sponsors/clue)
for details.

Let's take these projects to the next level together! 🚀

## Quickstart example

Once [installed](#install), you can use the following code to connect to your
local Redis server and send some requests:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$redis = new Clue\React\Redis\RedisClient('localhost:6379');

$redis->set('greeting', 'Hello world');
$redis->append('greeting', '!');

$redis->get('greeting')->then(function (string $greeting) {
    // Hello world!
    echo $greeting . PHP_EOL;
});

$redis->incr('invocation')->then(function (int $n) {
    echo 'This is invocation #' . $n . PHP_EOL;
});
```

See also the [examples](examples).

## Usage

### Commands

Most importantly, this project provides a [`RedisClient`](#redisclient) instance that
can be used to invoke all [Redis commands](https://redis.io/commands) (such as `GET`, `SET`, etc.).

```php
$redis = new Clue\React\Redis\RedisClient('localhost:6379');

$redis->get($key);
$redis->set($key, $value);
$redis->exists($key);
$redis->expire($key, $seconds);
$redis->mget($key1, $key2, $key3);

$redis->multi();
$redis->exec();

$redis->publish($channel, $payload);
$redis->subscribe($channel);

$redis->ping();
$redis->select($database);

// many more…
```

Each method call matches the respective [Redis command](https://redis.io/commands).
For example, the `$redis->get()` method will invoke the [`GET` command](https://redis.io/commands/get).

All [Redis commands](https://redis.io/commands) are automatically available as
public methods via the magic [`__call()` method](#__call).
Listing all available commands is out of scope here, please refer to the
[Redis command reference](https://redis.io/commands).

Any arguments passed to the method call will be forwarded as command arguments.
For example, the `$redis->set('name', 'Alice')` call will perform the equivalent of a
`SET name Alice` command. It's safe to pass integer arguments where applicable (for
example `$redis->expire($key, 60)`), but internally Redis requires all arguments to
always be coerced to string values.

Each of these commands supports async operation and returns a [Promise](#promises)
that eventually *fulfills* with its *results* on success or *rejects* with an
`Exception` on error. See also the following section about [promises](#promises)
for more details.

### Promises

Sending commands is async (non-blocking), so you can actually send multiple
commands in parallel.
Redis will respond to each command request with a response message, pending
commands will be pipelined automatically.

Sending commands uses a [Promise](https://github.com/reactphp/promise)-based
interface that makes it easy to react to when a command is completed
(i.e. either successfully fulfilled or rejected with an error):

```php
$redis->get($key)->then(function (?string $value) {
    var_dump($value);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

### PubSub

This library is commonly used to efficiently transport messages using Redis'
[Pub/Sub](https://redis.io/topics/pubsub) (Publish/Subscribe) channels. For
instance, this can be used to distribute single messages to a larger number
of subscribers (think horizontal scaling for chat-like applications) or as an
efficient message transport in distributed systems (microservice architecture).

The [`PUBLISH` command](https://redis.io/commands/publish) can be used to
send a message to all clients currently subscribed to a given channel:

```php
$channel = 'user';
$message = json_encode(['id' => 10]);
$redis->publish($channel, $message);
```

The [`SUBSCRIBE` command](https://redis.io/commands/subscribe) can be used to
subscribe to a channel and then receive incoming PubSub `message` events:

```php
$channel = 'user';
$redis->subscribe($channel);

$redis->on('message', function (string $channel, string $payload) {
    // pubsub message received on given $channel
    var_dump($channel, json_decode($payload));
});
```

Likewise, you can use the same client connection to subscribe to multiple
channels by simply executing this command multiple times:

```php
$redis->subscribe('user.register');
$redis->subscribe('user.join');
$redis->subscribe('user.leave');
```

Similarly, the [`PSUBSCRIBE` command](https://redis.io/commands/psubscribe) can
be used to subscribe to all channels matching a given pattern and then receive
all incoming PubSub messages with the `pmessage` event:


```php
$pattern = 'user.*';
$redis->psubscribe($pattern);

$redis->on('pmessage', function (string $pattern, string $channel, string $payload) {
    // pubsub message received matching given $pattern
    var_dump($channel, json_decode($payload));
});
```

Once you're in a subscribed state, Redis no longer allows executing any other
commands on the same client connection. This is commonly worked around by simply
creating a second client connection and dedicating one client connection solely
for PubSub subscriptions and the other for all other commands.

The [`UNSUBSCRIBE` command](https://redis.io/commands/unsubscribe) and
[`PUNSUBSCRIBE` command](https://redis.io/commands/punsubscribe) can be used to
unsubscribe from active subscriptions if you're no longer interested in
receiving any further events for the given channel and pattern subscriptions
respectively:

```php
$redis->subscribe('user');

Loop::addTimer(60.0, function () use ($redis) {
    $redis->unsubscribe('user');
});
```

Likewise, once you've unsubscribed the last channel and pattern, the client
connection is no longer in a subscribed state and you can issue any other
command over this client connection again.

Each of the above methods follows normal request-response semantics and return
a [`Promise`](#promises) to await successful subscriptions. Note that while
Redis allows a variable number of arguments for each of these commands, this
library is currently limited to single arguments for each of these methods in
order to match exactly one response to each command request. As an alternative,
the methods can simply be invoked multiple times with one argument each.

Additionally, can listen for the following PubSub events to get notifications
about subscribed/unsubscribed channels and patterns:

```php
$redis->on('subscribe', function (string $channel, int $total) {
    // subscribed to given $channel
});
$redis->on('psubscribe', function (string $pattern, int $total) {
    // subscribed to matching given $pattern
});
$redis->on('unsubscribe', function (string $channel, int $total) {
    // unsubscribed from given $channel
});
$redis->on('punsubscribe', function (string $pattern, int $total) {
    // unsubscribed from matching given $pattern
});
```

When the underlying connection is lost, the `unsubscribe` and `punsubscribe` events
will be invoked automatically. This gives you control over re-subscribing to the
channels and patterns as appropriate.

## API

### RedisClient

The `RedisClient` is responsible for exchanging messages with your Redis server
and keeps track of pending commands.

```php
$redis = new Clue\React\Redis\RedisClient('localhost:6379');

$redis->incr('hello');
```

Besides defining a few methods, this interface also implements the
`EventEmitterInterface` which allows you to react to certain events as documented below.

Internally, this class creates the underlying connection to Redis only on
demand once the first request is invoked on this instance and will queue
all outstanding requests until the underlying connection is ready.
This underlying connection will be reused for all requests until it is closed.
By default, idle connections will be held open for 1ms (0.001s) when not used.
The next request will either reuse the existing connection or will automatically
create a new underlying connection if this idle time is expired.

From a consumer side this means that you can start sending commands to the
database right away while the underlying connection may still be
outstanding. Because creating this underlying connection may take some
time, it will enqueue all oustanding commands and will ensure that all
commands will be executed in correct order once the connection is ready.

If the underlying database connection fails, it will reject all
outstanding commands and will return to the initial "idle" state. This
means that you can keep sending additional commands at a later time which
will again try to open a new underlying connection. Note that this may
require special care if you're using transactions (`MULTI`/`EXEC`) that are kept
open for longer than the idle period.

While using PubSub channels (see `SUBSCRIBE` and `PSUBSCRIBE` commands), this client
will never reach an "idle" state and will keep pending forever (or until the
underlying database connection is lost). Additionally, if the underlying
database connection drops, it will automatically send the appropriate `unsubscribe`
and `punsubscribe` events for all currently active channel and pattern subscriptions.
This allows you to react to these events and restore your subscriptions by
creating a new underlying connection repeating the above commands again.

Note that creating the underlying connection will be deferred until the
first request is invoked. Accordingly, any eventual connection issues
will be detected once this instance is first used. You can use the
`end()` method to ensure that the connection will be soft-closed
and no further commands can be enqueued. Similarly, calling `end()` on
this instance when not currently connected will succeed immediately and
will not have to wait for an actual underlying connection.

#### __construct()

The `new RedisClient(string $url, ConnectorInterface $connector = null, LoopInterface $loop = null)` constructor can be used to
create a new `RedisClient` instance.

The `$url` can be given in the
[standard](https://www.iana.org/assignments/uri-schemes/prov/redis) form
`[redis[s]://][:auth@]host[:port][/db]`.
You can omit the URI scheme and port if you're connecting to the default port 6379:

```php
// both are equivalent due to defaults being applied
$redis = new Clue\React\Redis\RedisClient('localhost');
$redis = new Clue\React\Redis\RedisClient('redis://localhost:6379');
```

Redis supports password-based authentication (`AUTH` command). Note that Redis'
authentication mechanism does not employ a username, so you can pass the
password `h@llo` URL-encoded (percent-encoded) as part of the URI like this:

```php
// all forms are equivalent
$redis = new Clue\React\Redis\RedisClient('redis://:h%40llo@localhost');
$redis = new Clue\React\Redis\RedisClient('redis://ignored:h%40llo@localhost');
$redis = new Clue\React\Redis\RedisClient('redis://localhost?password=h%40llo');
```

You can optionally include a path that will be used to select (SELECT command) the right database:

```php
// both forms are equivalent
$redis = new Clue\React\Redis\RedisClient('redis://localhost/2');
$redis = new Clue\React\Redis\RedisClient('redis://localhost?db=2');
```

You can use the [standard](https://www.iana.org/assignments/uri-schemes/prov/rediss)
`rediss://` URI scheme if you're using a secure TLS proxy in front of Redis:

```php
$redis = new Clue\React\Redis\RedisClient('rediss://redis.example.com:6340');
```

You can use the `redis+unix://` URI scheme if your Redis instance is listening
on a Unix domain socket (UDS) path:

```php
$redis = new Clue\React\Redis\RedisClient('redis+unix:///tmp/redis.sock');

// the URI MAY contain `password` and `db` query parameters as seen above
$redis = new Clue\React\Redis\RedisClient('redis+unix:///tmp/redis.sock?password=secret&db=2');

// the URI MAY contain authentication details as userinfo as seen above
// should be used with care, also note that database can not be passed as path
$redis = new Clue\React\Redis\RedisClient('redis+unix://:secret@/tmp/redis.sock');
```

This method respects PHP's `default_socket_timeout` setting (default 60s)
as a timeout for establishing the underlying connection and waiting for
successful authentication. You can explicitly pass a custom timeout value
in seconds (or use a negative number to not apply a timeout) like this:

```php
$redis = new Clue\React\Redis\RedisClient('localhost?timeout=0.5');
```

By default, idle connections will be held open for 1ms (0.001s) when not used.
The next request will either reuse the existing connection or will automatically
create a new underlying connection if this idle time is expired.
This ensures you always get a "fresh" connection and as such should not be
confused with a "keepalive" or "heartbeat" mechanism, as this will not
actively try to probe the connection. You can explicitly pass a custom
idle timeout value in seconds (or use a negative number to not apply a
timeout) like this:

```php
$redis = new Clue\React\Redis\RedisClient('localhost?idle=10.0');
```

If you need custom DNS, proxy or TLS settings, you can explicitly pass a
custom instance of the [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

```php
$connector = new React\Socket\Connector([
    'dns' => '127.0.0.1',
    'tcp' => [
        'bindto' => '192.168.10.1:0'
    ],
    'tls' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

$redis = new Clue\React\Redis\RedisClient('localhost', $connector);
```

This class takes an optional `LoopInterface|null $loop` parameter that can be used to
pass the event loop instance to use for this object. You can use a `null` value
here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
This value SHOULD NOT be given unless you're sure you want to explicitly use a
given event loop instance.

#### __call()

The `__call(string $name, string[] $args): PromiseInterface<mixed>` method can be used to
invoke the given command.

This is a magic method that will be invoked when calling any Redis command on this instance.
Each method call matches the respective [Redis command](https://redis.io/commands).
For example, the `$redis->get()` method will invoke the [`GET` command](https://redis.io/commands/get).

```php
$redis->get($key)->then(function (?string $value) {
    var_dump($value);
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

All [Redis commands](https://redis.io/commands) are automatically available as
public methods via this magic `__call()` method.
Listing all available commands is out of scope here, please refer to the
[Redis command reference](https://redis.io/commands).

Any arguments passed to the method call will be forwarded as command arguments.
For example, the `$redis->set('name', 'Alice')` call will perform the equivalent of a
`SET name Alice` command. It's safe to pass integer arguments where applicable (for
example `$redis->expire($key, 60)`), but internally Redis requires all arguments to
always be coerced to string values.

Each of these commands supports async operation and returns a [Promise](#promises)
that eventually *fulfills* with its *results* on success or *rejects* with an
`Exception` on error. See also [promises](#promises) for more details.

#### end()

The `end():void` method can be used to
soft-close the Redis connection once all pending commands are completed.

#### close()

The `close():void` method can be used to
force-close the Redis connection and reject all pending commands.

#### error event

The `error` event will be emitted once a fatal error occurs, such as
when the client connection is lost or is invalid.
The event receives a single `Exception` argument for the error instance.

```php
$redis->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

This event will only be triggered for fatal errors and will be followed
by closing the client connection. It is not to be confused with "soft"
errors caused by invalid commands.

#### close event

The `close` event will be emitted once the client connection closes (terminates).

```php
$redis->on('close', function () {
    echo 'Connection closed' . PHP_EOL;
});
```

See also the [`close()`](#close) method.

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org/).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

Once released, this project will follow [SemVer](https://semver.org/).
At the moment, this will install the latest development version:

```bash
composer require clue/redis-react:^3@dev
```

See also the [CHANGELOG](CHANGELOG.md) for details about version upgrades.

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on PHP 7.1 through current PHP 8+.
It's *highly recommended to use the latest supported PHP version* for this project.

We're committed to providing long-term support (LTS) options and to provide a
smooth upgrade path. You may target multiple versions at the same time to
support a wider range of PHP versions like this:

```bash
composer require "clue/redis-react:^3@dev || ^2"
```

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org/):

```bash
composer install
```

To run the test suite, go to the project root and run:

```bash
vendor/bin/phpunit
```

The test suite is set up to always ensure 100% code coverage across all
supported environments. If you have the Xdebug extension installed, you can also
generate a code coverage report locally like this:

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

The test suite contains both unit tests and functional integration tests.
The functional tests require access to a running Redis server instance
and will be skipped by default.

If you don't have access to a running Redis server, you can also use a temporary `Redis` Docker image:

```bash
docker run --net=host redis
```

To now run the functional tests, you need to supply *your* login
details in an environment variable like this:

```bash
REDIS_URI=localhost:6379 vendor/bin/phpunit
```

On top of this, we use PHPStan on max level to ensure type safety across the project:

```bash
vendor/bin/phpstan
```

## License

This project is released under the permissive [MIT license](LICENSE).

> Did you know that I offer custom development services and issuing invoices for
  sponsorships of releases and for contributions? Contact me (@clue) for details.
