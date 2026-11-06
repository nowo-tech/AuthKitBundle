<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

use function is_string;

/**
 * `magic_login.mode: disabled` also stops links already issued (1.37, GY-L4): the firewall's `login_link` consumes them
 * before any controller runs, so this refuses them on the firewall's own dispatcher. Registered per firewall with
 * `login_link`. Only AuthKit's own check routes count (they carry the profile): a host's own `login_link` route is
 * not AuthKit's feature and is left alone.
 */
final readonly class MagicLoginDisabledListener
{
    public function __construct(
        private RequestStack $requests,
        private MagicLoginGate $gate,
    ) {
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (MfaFirstFactor::fromAuthenticator($event->getAuthenticator()) !== 'login_link') {
            return;
        }
        $profile = $this->requests->getMainRequest()?->attributes->get(RequestProfileResolver::REQUEST_ATTRIBUTE);
        if (is_string($profile) && !$this->gate->isEnabled($profile)) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired login link.');
        }
    }
}
