<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 1.54.0 (REQ-ARCH-001 rule 6 deprecation layer) on the server of AUTH_KIT_LOAD_DATABASE_URL: in host mode (the kernel
 * leaves `doctrine.private_entity_manager` unset) an AuthKit write that flushes the application's manager reports the
 * application's pending changes once per unit of work, again after the boundary, and still writes them (1.x behaviour
 * unchanged). The manager is left open, with nothing pending and no transaction level left.
 */
final class HostFlushProbeTest extends LoadDatabaseTestCase
{
    public function testAnAuthKitWriteReportsTheApplicationsPendingChangesOncePerUnitOfWork(): void
    {
        $reports  = [];
        $previous = null;
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$reports, &$previous): bool {
            if (str_contains($message, 'were flushed by NowoAuthKitBundle')) {
                $reports[] = $message;

                return true;
            }

            return $previous !== null && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED);

        try {
            $this->em->persist((new KernelUser())->setEmail('pending-1@example.com')->setPassword('pending-password'));
            $this->invitation('ada@example.com');
            $this->em->persist((new KernelUser())->setEmail('pending-2@example.com')->setPassword('pending-password'));
            $this->invitation('bob@example.com');
            self::assertCount(1, $reports, 'one report per class and unit of work');

            $this->get('load.boundary', PrivateEntityManagerSubscriber::class)->clearState();
            $this->em->persist((new KernelUser())->setEmail('pending-3@example.com')->setPassword('pending-password'));
            $this->invitation('eve@example.com');
        } finally {
            restore_error_handler();
        }

        self::assertSame(['Your pending changes to ' . KernelUser::class . ' were flushed by NowoAuthKitBundle; 2.0 no longer does.', 'Your pending changes to ' . KernelUser::class . ' were flushed by NowoAuthKitBundle; 2.0 no longer does.'], $reports);
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email LIKE 'pending-%'"), 'host mode still writes them in 1.x');
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation'));
        $this->assertHealthy();
    }
}
