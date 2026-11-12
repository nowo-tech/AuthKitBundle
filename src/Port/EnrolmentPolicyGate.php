<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function method_exists;

/**
 * Asks a host {@see EnrolmentPolicyInterface} (1.46.0) where the bundle asks the deprecated
 * {@see EnrolmentGateInterface}: the token becomes an {@see EnrolmentCandidate} (user, firewall, AuthKit second factor
 * pending, impersonation, role names, scalar attributes). Built per call by {@see Ports}.
 *
 * @internal
 */
final readonly class EnrolmentPolicyGate implements EnrolmentGateInterface
{
    public function __construct(private EnrolmentPolicyInterface $policy)
    {
    }

    public function isComplete(TokenInterface $token): bool
    {
        return $this->policy->allowsEnrolment(self::candidate($token));
    }

    public static function candidate(TokenInterface $token): EnrolmentCandidate
    {
        $user     = $token->getUser();
        $firewall = method_exists($token, 'getFirewallName') ? $token->getFirewallName() : null;

        return new EnrolmentCandidate(
            $user instanceof UserInterface ? AuthUserRefs::fromUser($user) : null,
            is_string($firewall) && $firewall !== '' ? $firewall : null,
            $token instanceof MfaPendingToken,
            $token instanceof SwitchUserToken,
            $token->getRoleNames(),
            $token->getAttributes(),
            $token instanceof RememberMeToken,
            $token::class,
        );
    }
}
