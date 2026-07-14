<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Profile;

use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestProfileResolverTest extends TestCase
{
    public function testResolveUsesDefaultProfileWhenAttributeMissing(): void
    {
        $resolver = ProfileRegistryFactory::requestResolver(TestUser::class);

        $profile = $resolver->resolve(new Request());

        self::assertSame('default', $profile->name);
    }

    public function testResolveUsesNamedProfileFromRequestAttribute(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'default' => ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
            'admin'   => array_replace_recursive(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                ['registration_role' => 'ROLE_ADMIN'],
            ),
        ]);

        $resolver = new RequestProfileResolver($registry);
        $request  = new Request();
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'admin');

        $profile = $resolver->resolve($request);

        self::assertSame('admin', $profile->name);
        self::assertSame('ROLE_ADMIN', $profile->registrationRole);
    }
}
