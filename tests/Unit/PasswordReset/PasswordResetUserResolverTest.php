<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class PasswordResetUserResolverTest extends TestCase
{
    public function testFindByIdentifier(): void
    {
        $user = new TestUser();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['email' => 'user@example.com'])->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(TestUser::class)->willReturn($repository);

        $resolver = new PasswordResetUserResolver($entityManager, TestUser::class, 'email');

        self::assertSame($user, $resolver->findByIdentifier('user@example.com'));
    }
}
