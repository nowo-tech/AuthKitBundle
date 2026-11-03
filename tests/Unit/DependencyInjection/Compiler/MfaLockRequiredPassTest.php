<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MfaLockRequiredPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Audit DD-L3: MFA needs a lock factory so its attempt limits are atomic.
 */
final class MfaLockRequiredPassTest extends TestCase
{
    public function testWithoutMfaNothingIsRequired(): void
    {
        $container = new ContainerBuilder();
        (new MfaLockRequiredPass())->process($container);
        $container->setParameter('nowo_auth_kit.profiles', ['default' => ['mfa' => ['enabled' => false]], 'odd' => 'not-a-profile']);
        (new MfaLockRequiredPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testMfaWithoutALockFactoryFailsToCompile(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.profiles', ['admin' => ['mfa' => ['enabled' => true]]]);
        try {
            (new MfaLockRequiredPass())->process($container);
            self::fail('A lock factory is required.');
        } catch (LogicException $e) {
            self::assertStringContainsString('profiles.admin.mfa requires symfony/lock', $e->getMessage());
        }
        $container->register('lock.factory');
        (new MfaLockRequiredPass())->process($container);
    }
}
