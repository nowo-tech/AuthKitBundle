<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class MagicLoginUserResolverTest extends TestCase
{
    public function testFindsByIdentifier(): void
    {
        $user = new TestUser();
        $user->setEmail('a@b.c');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'a@b.c'])
            ->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(TestUser::class)->willReturn($repository);

        $resolver = new MagicLoginUserResolver($em, ProfileRegistryFactory::single(TestUser::class));

        self::assertSame($user, $resolver->findByIdentifier('a@b.c'));
    }

    public function testUsesNamedProfile(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $registry = ProfileRegistryFactory::fromProfiles([
            'default' => ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
            'admin'   => array_replace_recursive(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                [
                    'user_class' => TestUser::class,
                    'routes'     => [
                        'login'               => ['path' => '/admin/login', 'name' => 'admin_login'],
                        'logout'              => ['path' => '/admin/logout', 'name' => 'admin_logout'],
                        'register'            => ['path' => '/admin/register', 'name' => 'admin_register'],
                        'reset_request'       => ['path' => '/admin/reset', 'name' => 'admin_reset'],
                        'reset_password'      => ['path' => '/admin/reset/{token}', 'name' => 'admin_reset_token'],
                        'reset_password_code' => ['path' => '/admin/reset/code', 'name' => 'admin_reset_code'],
                        'magic_login_request' => ['path' => '/admin/magic', 'name' => 'admin_magic'],
                        'magic_login_check'   => ['path' => '/admin/magic/check', 'name' => 'admin_magic_check'],
                    ],
                ],
            ),
        ]);

        $resolver = new MagicLoginUserResolver($em, $registry);
        self::assertNull($resolver->findByIdentifier('x', 'admin'));
    }
}
