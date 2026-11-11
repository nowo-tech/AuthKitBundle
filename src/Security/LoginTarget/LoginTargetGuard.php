<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function in_array;
use function is_string;

/**
 * Post-login target guard (1.44, Beacon re-audit item 5): after any sign-in of a guarded firewall (form login, login
 * link, AuthKit's magic link / social / QR / registration sign-ins), a redirect to anything but a same-origin HTML page
 * — an API, JSON, asset, Mercure, health or ingest endpoint saved as `_target_path` by a background call of an expired
 * session — becomes a redirect to the fallback, and a refused saved target is removed from the session.
 *
 * Global `LoginSuccessEvent` listener at priority -40: after the firewall's own listeners, before AuthKit MFA (-48,
 * which keeps the target for after the second step) and remember-me (-64). The answer itself is kept (cookies and
 * headers other listeners set); only its `Location` changes.
 *
 * Stateless (worker safe).
 */
final readonly class LoginTargetGuard
{
    /**
     * @param list<string> $firewalls
     * @param array<string, string|null> $fallbackRoutes firewall => route name (null: `/`)
     */
    public function __construct(
        private LoginTargetPolicy $policy,
        private UrlGeneratorInterface $urls,
        private array $firewalls,
        private array $fallbackRoutes = [],
        private ?LoginTargetFallbackProviderInterface $fallbackProvider = null,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $firewall = $event->getFirewallName();
        if (!in_array($firewall, $this->firewalls, true)) {
            return;
        }
        $request = $event->getRequest();
        $this->forgetRefusedSavedTarget($request, $firewall);

        $response = $event->getResponse();
        if (!$response instanceof Response || !$response->isRedirection()) {
            return;
        }
        $location = $response->headers->get('Location');
        if (is_string($location) && $this->policy->allows($location, $request, $firewall)) {
            return;
        }
        $fallback = $this->fallback($request, $firewall, $event->getUser()->getUserIdentifier());
        if ($response instanceof RedirectResponse) {
            $response->setTargetUrl($fallback);

            return;
        }
        $response->headers->set('Location', $fallback);
    }

    /**
     * The URL a refused target is replaced with: the fallback provider's path when it is local, else the firewall's
     * fallback route, else the application root.
     */
    public function fallback(Request $request, string $firewall, string $userIdentifier): string
    {
        $provided = $this->fallbackProvider?->fallback($firewall, $userIdentifier);
        if (is_string($provided) && $provided !== '' && $provided[0] === '/' && LoginTargetPolicy::sameOriginParts($provided, $request) !== null) {
            return $provided;
        }
        $route = $this->fallbackRoutes[$firewall] ?? null;

        return is_string($route) && $route !== '' ? $this->urls->generate($route) : $request->getBaseUrl() . '/';
    }

    private function forgetRefusedSavedTarget(Request $request, string $firewall): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $key     = '_security.' . $firewall . '.target_path';
        $saved   = $session->get($key);
        if (is_string($saved) && !$this->policy->allows($saved, $request, $firewall)) {
            $session->remove($key);
        }
    }
}
