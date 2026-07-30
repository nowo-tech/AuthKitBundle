<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class SocialLoginGateTest extends TestCase
{
    public function testDisabledWhenModeDisabled(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([new SocialLoginCredential()]);

        $gate = new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'social_login' => ['mode' => 'disabled'],
        ]), $credentials);

        self::assertFalse($gate->isEnabled());
    }

    public function testDisabledWhenNoCredentials(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);

        $gate = new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'social_login' => ['mode' => 'enabled'],
        ]), $credentials);

        self::assertFalse($gate->isEnabled());
    }

    public function testEnabledWhenModeEnabledAndCredentialsExist(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([new SocialLoginCredential()]);

        $gate = new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'social_login' => ['mode' => 'enabled'],
        ]), $credentials);

        self::assertTrue($gate->isEnabled());
        self::assertTrue($gate->isEnabled('default'));
    }
}
