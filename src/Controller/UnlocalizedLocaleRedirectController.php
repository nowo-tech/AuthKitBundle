<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * Redirects bare auth URLs to the canonical localized route when locale.unlocalized=redirect.
 */
final class UnlocalizedLocaleRedirectController
{
    public function __construct(
        private readonly AuthKitUrlGenerator $urlGenerator,
    ) {
    }

    public function redirect(Request $request): RedirectResponse
    {
        $canonicalRoute = $request->attributes->get('_auth_kit_canonical_route');
        if (!is_string($canonicalRoute) || $canonicalRoute === '') {
            throw new NotFoundHttpException('Missing canonical Auth Kit route for unlocalized redirect.');
        }

        $parameters = $request->attributes->get('_route_params', []);
        if (!is_array($parameters)) {
            $parameters = [];
        }

        unset($parameters['_auth_kit_canonical_route'], $parameters['_auth_kit_profile'], $parameters['_locale']);

        $url = $this->urlGenerator->generate($canonicalRoute, $parameters);
        // SEC-004 L3: only same-origin, path-absolute targets (never `//host` or a scheme).
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            throw new NotFoundHttpException('Unsafe Auth Kit redirect target.');
        }

        // Keep the original query string (e.g. reset/magic-link tokens, target paths) on the redirect.
        $queryString = $request->getQueryString();
        if ($queryString !== null && $queryString !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        return new RedirectResponse($url);
    }
}
