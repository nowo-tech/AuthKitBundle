<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\EventSubscriber;

use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
use Nowo\AuthKitBundle\Mfa\MfaLoginCompleter;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records the time of a full sign-in in the session (2.4.0; the same attribute as the MFA one,
 * {@see MfaEnrolment::SIGNED_IN_AT}), so the passkey account page can ask for a recent sign-in on profiles without MFA.
 * A pending second step is recorded when it completes ({@see MfaLoginCompleter}); a
 * remember-me sign-in never counts. Stateless (worker safe).
 */
final readonly class WebauthnSignedInSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', -64]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        if ($token instanceof MfaPendingToken || $token instanceof RememberMeToken) {
            return;
        }
        MfaEnrolment::signedIn($event->getRequest(), $this->clock->now()->getTimestamp());
    }
}
