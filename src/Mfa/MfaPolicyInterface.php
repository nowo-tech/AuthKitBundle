<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host rule deciding whether a user must have a second factor (`mfa.policy`, 1.34), asked after
 * `mfa.required_roles` (a role requirement always wins). Called at sign-in and on the account page only.
 */
interface MfaPolicyInterface
{
    public function requirement(UserInterface $user, string $profile): MfaRequirement;
}
