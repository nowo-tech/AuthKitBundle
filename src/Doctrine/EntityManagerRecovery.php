<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Throwable;

/**
 * Runs bundle writes and reopens entity managers closed by a failed flush.
 *
 * Without a kernel reset between requests (FrankenPHP worker mode), a closed manager would
 * otherwise make every later request served by the same worker fail. DoctrineBundle resets
 * lazy managers in place, so services holding the manager keep a usable reference.
 */
final class EntityManagerRecovery
{
    public function __construct(
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    public function flush(EntityManagerInterface $entityManager): void
    {
        $this->run(static function () use ($entityManager): void {
            $entityManager->flush();
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function run(callable $write): mixed
    {
        try {
            return $write();
        } catch (Throwable $exception) {
            $this->resetClosedManagers();

            throw $exception;
        }
    }

    public function resetClosedManagers(): void
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return;
        }

        foreach ($this->registry->getManagers() as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);
            }
        }
    }
}
