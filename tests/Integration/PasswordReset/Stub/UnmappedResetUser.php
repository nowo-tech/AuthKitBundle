<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Stub;

use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;

/**
 * A subclass of the profile's user class that the mapping does not know (no entity attribute): AuthKit's manager
 * cannot address it.
 */
final class UnmappedResetUser extends ResetUser
{
}
