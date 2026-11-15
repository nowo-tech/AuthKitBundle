<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * 1.50.0: gives a mocked DBAL connection a real transaction nesting level (begin / commit / rollBack move it), as
 * AuthKit's transaction runner (nowo-tech/transaction-doctrine) reads it to roll a failed unit back to its entry level.
 */
final class NestingLevels
{
    public int $level = 0;

    public static function on(Connection&MockObject $connection): self
    {
        $levels = new self();
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static fn (): int => $levels->level);
        $connection->method('isTransactionActive')->willReturnCallback(static fn (): bool => $levels->level > 0);
        $connection->method('beginTransaction')->willReturnCallback(static function () use ($levels): void {
            ++$levels->level;
        });
        $connection->method('commit')->willReturnCallback(static function () use ($levels): void {
            --$levels->level;
        });

        return $levels;
    }

    /**
     * The callback of an expected `rollBack()` (it lowers the level).
     *
     * @return callable(): void
     */
    public function rollBack(): callable
    {
        return function (): void {
            --$this->level;
        };
    }
}
