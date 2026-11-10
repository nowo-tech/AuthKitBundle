<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub\__CG__\Nowo\AuthKitBundle\Tests\Stub;

use Nowo\AuthKitBundle\User\UserIds;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Named like a Doctrine proxy of {@see \Nowo\AuthKitBundle\Tests\Stub\TestUser} (`…\__CG__\<real class>`), for
 * {@see UserIds::className()}.
 */
final class TestUser implements UserInterface
{
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'proxy@example.com';
    }
}
