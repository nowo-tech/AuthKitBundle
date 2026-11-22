<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;

use function strstr;
use function ucfirst;

/**
 * Host gate and presenter of the custom kernel: `support@` never joins the switcher (its own MFA is "pending");
 * accounts are shown as "Member Account" with an avatar.
 */
final class HostAccountPolicy implements EnrolmentPolicyInterface, AccountPresentationProviderInterface
{
    public function allowsEnrolment(EnrolmentCandidate $candidate): bool
    {
        return $candidate->user?->identifier !== 'support@example.com';
    }

    public function presentationFor(AuthUserRef $user): AccountPresentation
    {
        return new AccountPresentation(ucfirst((string) strstr($user->identifier, '@', true)) . ' Account', $user->identifier, '/avatars/' . $user->identifier . '.png');
    }
}
