<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Builds the framework-free {@see AuthRequestContext} of a Symfony request (1.46.0, REQ-ARCH-001 rule 3). The context
 * reads the request and its session through hidden closures, per call; nothing is copied or stored. Stateless.
 *
 * @internal
 */
final class AuthRequestContexts
{
    public static function fromRequest(Request $request, ?string $firewall = null, ?string $profile = null): AuthRequestContext
    {
        $route = $request->attributes->get('_route');

        return AuthRequestContext::withReader(
            new AuthRequestContext(
                $request->getMethod(),
                $request->getPathInfo(),
                is_string($route) ? $route : null,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                $request->getLocale(),
                $firewall,
                $profile,
            ),
            static fn (string $bag, string $name): mixed => match ($bag) {
                'header' => $request->headers->get($name),
                'query'  => $request->query->all()[$name] ?? null,
                'post'   => $request->request->all()[$name] ?? null,
                default  => $request->hasSession() ? $request->getSession()->get($name) : null,
            },
            static fn (string $name): bool => $request->hasSession() && $request->getSession()->has($name),
            static fn (string $name): mixed => $request->hasSession() ? $request->getSession()->remove($name) : null,
        );
    }
}
