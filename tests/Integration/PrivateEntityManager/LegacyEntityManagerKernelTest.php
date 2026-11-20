<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\HttpFoundation\Request;

/**
 * The 1.45 behaviour kept by default (no `doctrine.private_entity_manager`): an AuthKit write flushes the application's
 * entity manager, including a change the application left pending. Documents what {@see PrivateEntityManagerKernelTest}
 * changes when the option is on.
 *
 * 1.54.0: the host-flush probe reports that pending change (2.0 no longer writes it).
 */
final class LegacyEntityManagerKernelTest extends MfaKernelTestCase
{
    #[IgnoreDeprecations]
    public function testByDefaultAnAuthKitWriteFlushesTheApplicationsPendingChanges(): void
    {
        $this->expectUserDeprecationMessage('Your pending changes to ' . KernelUser::class . ' were flushed by NowoAuthKitBundle; 2.0 no longer does.');
        self::assertFalse(self::getContainer()->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse(self::getContainer()->has(AuthKitEntityManager::class), 'No private manager is registered.');

        $this->em()->persist((new KernelUser())->setEmail('pending@example.com')->setPassword('pending-password'));
        $qr = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        $qr->create(Request::create('/login/qr'));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"));
    }
}
