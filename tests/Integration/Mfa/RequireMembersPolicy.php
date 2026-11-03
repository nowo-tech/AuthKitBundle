<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\MfaPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host policy of the sessions kernel: `support@` must have MFA (besides the ROLE_ADMIN rule).
 */
final class RequireMembersPolicy implements MfaPolicyInterface
{
    public function requirement(UserInterface $user, string $profile): MfaRequirement
    {
        return $user->getUserIdentifier() === 'support@example.com' ? MfaRequirement::Required : MfaRequirement::Optional;
    }
}
