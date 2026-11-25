<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

/**
 * Resolves a host name to its addresses before an OIDC fetch, so every address can be checked and the connection
 * pinned to a checked one (no DNS rebinding between the check and the connect). Replaceable for tests or for hosts
 * with their own resolver.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name does not resolve
     */
    public function resolve(string $host): array;
}
