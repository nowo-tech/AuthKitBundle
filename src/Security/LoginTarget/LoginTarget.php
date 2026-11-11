<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

/**
 * A same-origin post-login target, as {@see LoginTargetVoterInterface} sees it (plain values, no HTTP types).
 *
 * - `path`: the path without the application base URL (`/projects/42`), never empty, always starting with one `/`.
 * - `query`: the query string without `?`, or null.
 * - `routeName`: the GET route the path matches, or null when none matches.
 * - `firewall`: the firewall of the sign-in.
 */
final readonly class LoginTarget
{
    public function __construct(
        public string $path,
        public ?string $query,
        public ?string $routeName,
        public string $firewall,
    ) {
    }
}
