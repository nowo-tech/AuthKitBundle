<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

use function getenv;
use function is_numeric;
use function usleep;

/**
 * Audit QG-M1 tests: in a writer process started with `AUTH_KIT_LOAD_SLOW_PERSIST=<seconds>`, the persist of a new
 * account waits that long, so the unit that creates it stays open (after its "no account yet" check, before its insert)
 * while the other writer runs. Without the variable it does nothing.
 */
final class SlowUserPersist
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $seconds = getenv('AUTH_KIT_LOAD_SLOW_PERSIST');
        if ($args->getObject() instanceof KernelUser && is_numeric($seconds)) {
            usleep((int) ((float) $seconds * 1_000_000));
        }
    }
}
