<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

/**
 * Optional host port (1.44): the page a sign-in lands on when its saved target is refused (for example a per-user
 * start page). Configure it with `nowo_auth_kit.login_target.fallback_provider`.
 *
 * Return a local path (`/dashboard`, `/app/start?tab=2`) or null for the configured fallback. A returned value that is
 * not a same-origin path is ignored (the configured fallback is used).
 */
interface LoginTargetFallbackProviderInterface
{
    public function fallback(string $firewall, string $userIdentifier): ?string;
}
