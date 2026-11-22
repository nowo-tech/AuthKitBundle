<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host policy of the sessions kernel: `support@` must have MFA (besides the ROLE_ADMIN rule).
 */
final class RequireMembersPolicy implements MfaRequirementPolicyInterface
{
    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement
    {
        return $user->identifier === 'support@example.com' ? MfaRequirement::Required : MfaRequirement::Optional;
    }
}
