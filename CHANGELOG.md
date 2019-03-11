# Changelog

## 2.3.0 (2019-03-11)

*   Feature: Add new `createLazyClient()` method to connect only on demand and
    implement "idle" timeout to close underlying connection when unused.
    (#87 and #88 by @clue and #82 by @WyriHaximus)

    ```php
    $client = $factory->createLazyClient('redis://localhost:6379');

    $client->incr('hello');
    $client->end();
    ```

*   Feature: Support cancellation of pending connection attempts.
    (#85 by @clue)

    ```php
    $promise = $factory->createClient($redisUri);

    $loop->addTimer(3.0, function () use ($promise) {
        $promise->cancel();
    });
    ```

*   Feature: Support connection timeouts.
    (#86 by @clue)

    ```php
    $factory->createClient('localhost?timeout=0.5');
    ```

*   Feature: Improve Exception messages for connection issues.
    (#89 by @clue)

    ```php
    $factory->createClient('redis://localhost:6379')->then(
        function (Client $client) {
            // client connected (and authenticated)
        },
        function (Exception $e) {
            // an error occurred while trying to connect (or authenticate) client
            echo $e->getMessage() . PHP_EOL;
            if ($e->getPrevious()) {
                echo $e->getPrevious()->getMessage() . PHP_EOL;
            }
        }
    );
    ```

*   Improve test suite structure and add forward compatibility with PHPUnit 7 and PHPUnit 6
    and test against PHP 7.1, 7.2, and 7.3 on TravisCI.
    (#83 by @WyriHaximus and #84 by @clue)

*   Improve documentation and update project homepage.
    (#81 and #90 by @clue)

## 2.2.0 (2018-01-24)

*   Feature: Support communication over Unix domain sockets (UDS)
    (#70 by @clue)

    ```php
    // new: now supports redis over Unix domain sockets (UDS)
    $factory->createClient('redis+unix:///tmp/redis.sock');
    ```

## 2.1.0 (2017-09-25)

*   Feature: Update Socket dependency to support hosts file on all platforms
    (#66 by @clue)

    This means that connecting to hosts such as `localhost` (and for example
    those used for Docker containers) will now work as expected across all
    platforms with no changes required:

    ```php
    $factory->createClient('localhost');
    ```

## 2.0.0 (2017-09-20)

A major compatibility release to update this package to support all latest
ReactPHP components!

This update involves a minor BC break due to dropped support for legacy
versions. We've tried hard to avoid BC breaks where possible and minimize impact
otherwise. We expect that most consumers of this package will actually not be
affected by any BC breaks, see below for more details.

*   BC break: Remove all deprecated APIs, default to `redis://` URI scheme
    and drop legacy SocketClient in favor of new Socket component.
    (#61 by @clue)

    >   All of this affects the `Factory` only, which is mostly considered
        "advanced usage". If you're affected by this BC break, then it's
        recommended to first update to the intermediary v1.2.0 release, which
        allows you to use the `redis://` URI scheme and a standard
        `ConnectorInterface` and then update to this version without causing a
        BC break.

*   BC break: Remove uneeded `data` event and support for advanced `MONITOR`
    command for performance and consistency reasons and
    remove underdocumented `isBusy()` method.
    (#62, #63 and #64 by @clue)

*   Feature: Forward compatibility with upcoming Socket v1.0 and v0.8 and EventLoop v1.0 and Evenement v3
    (#65 by @clue)

## 1.2.0 (2017-09-19)

*   Feature: Support `redis[s]://` URI scheme and deprecate legacy URIs
    (#60 by @clue)

    ```php
    $factory->createClient('redis://:secret@localhost:6379/4');
    $factory->createClient('redis://localhost:6379?password=secret&db=4');
    ```

*   Feature: Factory accepts Connector from Socket and deprecate legacy SocketClient
    (#59 by @clue)

    If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
    proxy servers etc.), you can explicitly pass a custom instance of the
    [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

    ```php
    $connector = new \React\Socket\Connector($loop, array(
        'dns' => '127.0.0.1',
        'tcp' => array(
            'bindto' => '192.168.10.1:0'
        ),
        'tls' => array(
            'verify_peer' => false,
            'verify_peer_name' => false
        )
    ));

    $factory = new Factory($loop, $connector);
    ```

## 1.1.0 (2017-09-18)

* Feature: Update SocketClient dependency to latest version
  (#58 by @clue)

* Improve test suite by adding PHPUnit to require-dev,
  fix HHVM build for now again and ignore future HHVM build errors,
  lock Travis distro so new defaults will not break the build and
  skip functional integration tests by default
  (#52, #53, #56 and #57 by @clue)

## 1.0.0 (2016-05-20)

* First stable release, now following SemVer

* BC break: Consistent public API, mark internal APIs as such
  (#38 by @clue)

  ```php
  // old
  $client->on('data', function (MessageInterface $message, Client $client) {
      // process an incoming message (raw message object)
  });

  // new
  $client->on('data', function (MessageInterface $message) use ($client) {
      // process an incoming message (raw message object)
  });
  ```

> Contains no other changes, so it's actually fully compatible with the v0.5.2 release.

## 0.5.2 (2016-05-20)

* Fix: Do not send empty SELECT statement when no database has been given
  (#35, #36 by @clue)

* Improve documentation, update dependencies and add first class support for PHP 7

## 0.5.1 (2015-01-12)

* Fix: Fix compatibility with react/promise v2.0 for monitor and PubSub commands.
  (#28)

## 0.5.0 (2014-11-12)

* Feature: Support PubSub commands (P)(UN)SUBSCRIBE and watching for "message",
  "subscribe" and "unsubscribe" events
  (#24)

* Feature: Support MONITOR command and watching for "monitor" events
  (#23)

* Improve documentation, update locked dependencies and add first class support for HHVM
  (#25, #26 and others)

## 0.4.0 (2014-08-25)

* BC break: The `Client` class has been renamed to `StreamingClient`.
  Added new `Client` interface.
  (#18 and #19)

* BC break: Rename `message` event to `data`.
  (#21)

* BC break: The `Factory` now accepts a `LoopInterface` as first argument.
  (#22)

* Fix: The `close` event will be emitted once when invoking the `Client::close()`
  method or when the underlying stream closes.
  (#20)

* Refactored code, improved testability, extended test suite and better code coverage.
  (#11, #18 and #20)

> Note: This is an intermediary release to ease upgrading to the imminent v0.5 release.

## 0.3.0 (2014-05-31)

* First tagged release

> Note: Starts at v0.3 because previous versions were not tagged. Leaving some
> room in case they're going to be needed in the future.

## 0.0.0 (2013-07-05)

* Initial concept
