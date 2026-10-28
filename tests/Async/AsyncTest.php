<?php

declare(strict_types=1);

namespace Keel\Tests\Async;

use Keel\Async\CancelledException;
use Keel\Async\TimeoutException;
use PHPUnit\Framework\TestCase;

use function Keel\Async\await_all;
use function Keel\Async\delay;

final class AsyncTest extends TestCase
{
    public function testOperationsRunConcurrently(): void
    {
        $start = microtime(true);
        $results = await_all(
            static function (): string { delay(0.2); return 'a'; },
            static function (): string { delay(0.2); return 'b'; },
            static function (): string { delay(0.2); return 'c'; },
        );
        $elapsed = microtime(true) - $start;

        self::assertSame(['a', 'b', 'c'], $results);
        self::assertLessThan(0.4, $elapsed, 'Three 200 ms waits should overlap.');
    }

    public function testStringKeysArePreserved(): void
    {
        $results = await_all(user: static fn (): int => 1, orders: static fn (): int => 2);
        self::assertSame(['user' => 1, 'orders' => 2], $results);
    }

    public function testTimeoutCancelsEveryOperation(): void
    {
        $cancelled = 0;
        $start = microtime(true);
        try {
            await_all(
                static function () use (&$cancelled): void {
                    try {
                        delay(5);
                    } catch (CancelledException $e) {
                        $cancelled++;
                        throw $e;
                    }
                },
                static fn (): int => 1,
                timeout: 0.1,
            );
            self::fail('Expected a timeout.');
        } catch (TimeoutException) {
        }

        self::assertSame(1, $cancelled);
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testAFailureCancelsSiblingsAndIsRethrown(): void
    {
        $siblingFinished = false;
        try {
            await_all(
                static function (): void { delay(0.05); throw new \DomainException('boom'); },
                static function () use (&$siblingFinished): void { delay(2); $siblingFinished = true; },
            );
            self::fail('Expected the failure to propagate.');
        } catch (\DomainException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertFalse($siblingFinished);
    }

    public function testNestedAwaitAll(): void
    {
        $start = microtime(true);
        $results = await_all(
            static fn (): array => await_all(static function (): int { delay(0.1); return 1; }, static function (): int { delay(0.1); return 2; }),
            static function (): int { delay(0.1); return 3; },
        );
        self::assertSame([[1, 2], 3], $results);
        self::assertLessThan(0.3, microtime(true) - $start);
    }
}
