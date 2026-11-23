<?php

declare(strict_types=1);

namespace NowoRegistryTestProxies\__CG__\Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity;

use Doctrine\Persistence\Proxy;

/**
 * Audit ZZ-A-L2: a classic Doctrine proxy of ResetUser, named as Doctrine names them (`<namespace>\__CG__\<entity>`),
 * so the metadata factory resolves it to the entity while the attribute driver sees no mapping on the class itself.
 *
 * @implements Proxy<\Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser>
 */
final class ResetUser extends \Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser implements Proxy
{
    public function __load(): void
    {
    }

    public function __isInitialized(): bool
    {
        return true;
    }
}
