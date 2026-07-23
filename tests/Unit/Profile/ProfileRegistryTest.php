<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Profile;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Profile\UnknownProfileException;
use Nowo\AuthKitBundle\Tests\Stub\ChildTestUser;
use Nowo\AuthKitBundle\Tests\Stub\ParentTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Processor;

final class ProfileRegistryTest extends TestCase
{
    public function testResolveUsesExactClassMap(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);

        $profile = $registry->resolveForObject(new TestUser());
        self::assertNotNull($profile);
        self::assertSame('default', $profile->name);
    }

    public function testResolveUsesInstanceOfWhenClassDiffers(): void
    {
        $registry = ProfileRegistryFactory::single(ParentTestUser::class);

        $profile = $registry->resolveForObject(new ChildTestUser());

        self::assertNotNull($profile);
        self::assertSame('default', $profile->name);
    }

    public function testResolveCachesResults(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);
        $user     = new TestUser();

        self::assertSame($registry->resolveForObject($user), $registry->resolveForObject($user));
    }

    public function testResolveReturnsNullForUnknownClass(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);

        self::assertNull($registry->resolveForObject(new stdClass()));
    }

    public function testGetDefaultAndAll(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);

        self::assertSame('default', $registry->getDefault()->name);
        self::assertArrayHasKey('default', $registry->all());
    }

    public function testHasPasswordResetEnabled(): void
    {
        $disabled = ProfileRegistryFactory::single(TestUser::class);
        self::assertFalse($disabled->hasPasswordResetEnabled());

        $enabled = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]);
        self::assertTrue($enabled->hasPasswordResetEnabled());
    }

    public function testHasRegistrationEnabled(): void
    {
        $disabled = ProfileRegistryFactory::single(TestUser::class, [
            'registration_mode' => 'disabled',
        ]);
        self::assertFalse($disabled->hasRegistrationEnabled());

        $enabled = ProfileRegistryFactory::single(TestUser::class, [
            'registration_mode' => 'always',
        ]);
        self::assertTrue($enabled->hasRegistrationEnabled());
    }

    public function testUnknownProfileThrows(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);

        $this->expectException(UnknownProfileException::class);
        $registry->getByName('missing');
    }

    public function testLegacyFlatConfigurationIsNormalizedViaExtension(): void
    {
        $config = (new Processor())->processConfiguration(
            new Configuration(),
            [[
                'user_class'        => TestUser::class,
                'registration_mode' => 'always',
            ]],
        );

        self::assertSame(TestUser::class, $config['profiles']['default']['user_class']);
        self::assertSame('always', $config['profiles']['default']['registration_mode']);
    }
}
