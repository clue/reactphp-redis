<?php

namespace Clue\Tests\React\Redis;

use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;
use React\Promise\PromiseInterface;

class TestCase extends BaseTestCase
{
    protected function expectCallableOnce(): callable
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->once())->method('__invoke');
        assert(is_callable($mock));

        return $mock;
    }

    /** @param mixed $argument */
    protected function expectCallableOnceWith($argument): callable
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->once())->method('__invoke')->with($argument);
        assert(is_callable($mock));

        return $mock;
    }

    protected function expectCallableNever(): callable
    {
        $mock = $this->createCallableMock();
        $mock->expects($this->never())->method('__invoke');
        assert(is_callable($mock));

        return $mock;
    }

    protected function createCallableMock(): MockObject
    {
        if (method_exists(MockBuilder::class, 'addMethods')) {
            // @phpstan-ignore-next-line requires PHPUnit 9+
            return $this->getMockBuilder(\stdClass::class)->addMethods(['__invoke'])->getMock();
        } else {
            // legacy PHPUnit < 9
            return $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        }
    }

    protected function expectPromiseResolve(PromiseInterface $promise): PromiseInterface
    {
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then(null, function(\Exception $error) {
            $this->assertNull($error);
            $this->fail('promise rejected');
        });
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        return $promise;
    }

    protected function expectPromiseReject(PromiseInterface $promise): PromiseInterface
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
