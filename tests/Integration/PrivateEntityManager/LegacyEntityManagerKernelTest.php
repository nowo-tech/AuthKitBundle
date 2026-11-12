<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The 1.45 behaviour kept by default (no `doctrine.private_entity_manager`): an AuthKit write flushes the application's
 * entity manager, including a change the application left pending. Documents what {@see PrivateEntityManagerKernelTest}
 * changes when the option is on.
 */
final class LegacyEntityManagerKernelTest extends MfaKernelTestCase
{
    public function testByDefaultAnAuthKitWriteFlushesTheApplicationsPendingChanges(): void
    {
        self::assertFalse(self::getContainer()->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse(self::getContainer()->has(AuthKitEntityManager::class), 'No private manager is registered.');

        $this->em()->persist((new KernelUser())->setEmail('pending@example.com')->setPassword('pending-password'));
        $qr = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        $qr->create(Request::create('/login/qr'));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"));
    }
}
