# Changelog

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
