<?php

namespace Clue\Tests\React\Redis;

use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase as BaseTestCase;
use React\Promise\PromiseInterface;

class TestCase extends BaseTestCase
{
    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->once())->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($argument)
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->once())->method('__invoke')->with($argument);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->never())->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        if (method_exists(MockBuilder::class, 'addMethods')) {
            // PHPUnit 9+
            return $this->getMockBuilder(\stdClass::class)->addMethods(['__invoke'])->getMock();
        } else {
            // legacy PHPUnit < 9
            return $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        }
    }

    protected function expectPromiseResolve($promise)
    {
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then(null, function($error) {
            $this->assertNull($error);
            $this->fail('promise rejected');
        });
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        return $promise;
    }

    protected function expectPromiseReject($promise)
    {
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then(function($value) {
            $this->assertNull($value);
            $this->fail('promise resolved');
        });

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        return $promise;
    }
}
