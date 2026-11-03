<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * `IS_MFA_IN_PROGRESS`: granted while the sign-in waits for its second step ({@see MfaPendingToken}), denied
 * otherwise (1.34).
 */
final class MfaVoter implements VoterInterface, CacheableVoterInterface
{
    public const IS_MFA_IN_PROGRESS = 'IS_MFA_IN_PROGRESS';

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        foreach ($attributes as $attribute) {
            if ($attribute === self::IS_MFA_IN_PROGRESS) {
                return $token instanceof MfaPendingToken ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
            }
        }

        return self::ACCESS_ABSTAIN;
    }

    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::IS_MFA_IN_PROGRESS;
    }

    public function supportsType(string $subjectType): bool
    {
        return true;
    }
}
