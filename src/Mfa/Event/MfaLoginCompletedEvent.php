<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Nowo\AuthKitBundle\Port\AuthRequestContexts;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * A sign-in that waited for its second step is now complete: the final token is stored and the session id migrated.
 * Host `LoginSuccessEvent` listeners that must not run for a half-authenticated user listen to this event instead
 * (or skip `LoginSuccessEvent` while its token is an `MfaPendingToken`).
 */
final class MfaLoginCompletedEvent extends MfaEvent
{
    /**
     * @param TokenInterface $token @deprecated since 1.46.0, use userRef() and firewall(); removed in 2.0
     * @param Request $request @deprecated since 1.46.0, use requestContext(); removed in 2.0
     */
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly TokenInterface $token,
        public readonly Request $request,
        public readonly string $firstFactor,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The firewall of the final token (1.46.0), null when the token does not name one.
     */
    public function firewall(): ?string
    {
        $name = method_exists($this->token, 'getFirewallName') ? $this->token->getFirewallName() : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The request that completed the sign-in, framework-free (1.46.0).
     */
    public function requestContext(): AuthRequestContext
    {
        return AuthRequestContexts::fromRequest($this->request, $this->firewall(), $this->profile);
    }
}
