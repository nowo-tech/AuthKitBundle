<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function is_string;
use function sprintf;

/**
 * Placeholder route intercepted by the Symfony logout listener.
 */
final class LogoutController
{
    #[Route(path: '/logout', name: 'nowo_auth_kit_logout', methods: ['GET'])]
    public function logout(?Request $request = null): never
    {
        $route = $request?->attributes->get('_route');

        throw new LogicException(sprintf('The logout route "%s" (%s) reached LogoutController: the firewall did not intercept it. Set the firewall "logout.path" to this route name; with nowo_auth_kit.locale.in_path "both" and unlocalized "serve" use the bare route (e.g. "nowo_auth_kit_logout_unlocalized") and enable locale.localized_logout_redirect, or run nowo:auth-kit:configure-security --force (see UPGRADING 1.32.2).', is_string($route) ? $route : 'unknown', $request?->getPathInfo() ?? 'unknown path'));
    }
}
