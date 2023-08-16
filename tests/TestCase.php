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

    /**
     * @param PromiseInterface<mixed> $promise
     */
    protected function expectPromiseResolve(PromiseInterface $promise): void
    {
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then(null, function(\Throwable $error) {
            $this->assertNull($error);
            $this->fail('promise rejected');
        });
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }
}
