# CHANGELOG

This file is a manually maintained list of changes for each release. Feel free
to add your changes here when sending pull requests. Also send corrections if
you spot any mistakes.

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
