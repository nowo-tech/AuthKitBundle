<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * 2.7.1: a remember-me session may now reach the login pages (to confirm its identity before a page that needs a full
 * sign-in). When someone signs in there as **another** account, the remembered account's cookie is cleared: otherwise,
 * once the new session expired, the browser would silently come back as the remembered account. Symfony's remember-me
 * listener (priority -64) already cancels the old cookie for authenticators with a remember-me badge (form login); this
 * covers every other sign-in (passkey, programmatic, login links) and issues nothing: the new account gets its own
 * cookie only through the remember-me listener when "Remember me" is ticked.
 *
 * Stateless (worker safe).
 */
final readonly class RememberedIdentitySwitchSubscriber implements EventSubscriberInterface
{
    /**
     * @param ContainerInterface $rememberMeHandlers the firewalls' remember-me handlers, by firewall name
     */
    public function __construct(private ContainerInterface $rememberMeHandlers)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', 0]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $previous = $event->getPreviousToken();
        if (!$previous instanceof RememberMeToken || $previous->getUserIdentifier() === $event->getAuthenticatedToken()->getUserIdentifier()) {
            return;
        }
        $firewall = $event->getFirewallName();
        $handler  = $this->rememberMeHandlers->has($firewall) ? $this->rememberMeHandlers->get($firewall) : null;
        if ($handler instanceof RememberMeHandlerInterface) {
            // @igor-ignore - Sets the clearing cookie on the current request's attributes, not a mutation of worker state.
            $handler->clearRememberMeCookie();
        }
    }
}
