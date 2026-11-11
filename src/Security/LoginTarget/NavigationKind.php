<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use Symfony\Component\HttpFoundation\Request;

use function str_contains;
use function strtolower;
use function trim;

/**
 * Tells a page navigation from a background call (fetch, XHR, EventSource, an image, a script…).
 *
 * Fetch metadata (`Sec-Fetch-Mode` / `Sec-Fetch-Dest`, sent by every current browser) decides when present. Without it
 * (old browsers, scripts): `X-Requested-With: XMLHttpRequest` or an `Accept` that names JSON and no HTML is a background
 * call; anything else is treated as a page, and only a request that asks for HTML (or sends no `Accept`) may become the
 * post-login target.
 *
 * Stateless: safe in FrameworkBundle / FrankenPHP worker mode.
 */
final class NavigationKind
{
    /**
     * @codeCoverageIgnore Static helper: never instantiated
     */
    private function __construct()
    {
    }

    /**
     * The caller shows the answer as a page (a redirect to the login page is fine): a navigation, top level or in a frame.
     */
    public static function expectsHtml(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return false;
        }
        $mode = self::header($request, 'Sec-Fetch-Mode');
        if ($mode !== null) {
            return $mode === 'navigate';
        }

        return !self::acceptsJsonOnly($request);
    }

    /**
     * The request may be saved as `_security.<firewall>.target_path`: a top-level GET page navigation.
     */
    public static function isTargetPathCandidate(Request $request): bool
    {
        if (!$request->isMethod(Request::METHOD_GET) || !self::expectsHtml($request)) {
            return false;
        }
        if (self::header($request, 'Sec-Fetch-Mode') === null) {
            return self::acceptsHtml($request);
        }
        $dest = self::header($request, 'Sec-Fetch-Dest');

        return $dest === null || $dest === 'document';
    }

    /**
     * `Accept` names JSON and no HTML (`application/json`, `application/problem+json`, `application/ld+json`…).
     */
    private static function acceptsJsonOnly(Request $request): bool
    {
        $accept = self::header($request, 'Accept') ?? '';

        return str_contains($accept, 'json') && !self::acceptsHtml($request);
    }

    private static function acceptsHtml(Request $request): bool
    {
        $accept = self::header($request, 'Accept');
        if ($accept === null) {
            return true;
        }

        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    private static function header(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }
}
