<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Re-authentication of a remember-me session (2.7.1).
 *
 * A session restored from the remember-me cookie is only `IS_AUTHENTICATED_REMEMBERED`: a page that needs
 * `IS_AUTHENTICATED_FULLY` sends it to the login page, which must show the form (to confirm the identity) instead of
 * bouncing it to the success route as a signed-in user. The saved target path is kept, so a successful sign-in returns
 * to the page that asked for it. Signing in as another account follows the normal login semantics (the new token
 * replaces the remembered one, the session is migrated).
 *
 * Stateless (worker safe).
 */
final class Reauthentication
{
    /** Query parameter of a re-authentication link (a same-origin path of this application). */
    public const TARGET_PARAMETER = '_target_path';

    /**
     * @codeCoverageIgnore Static helper: never instantiated
     */
    private function __construct()
    {
    }

    /**
     * The token is a remember-me session (a user, not fully authenticated).
     */
    public static function isRememberedOnly(?TokenInterface $token): bool
    {
        return $token instanceof RememberMeToken && $token->getUser() instanceof UserInterface;
    }

    /**
     * The token is a signed-in user that is fully authenticated (the login pages send it to the success route).
     */
    public static function isFullySignedIn(?TokenInterface $token): bool
    {
        return $token?->getUser() instanceof UserInterface && !self::isRememberedOnly($token);
    }

    /**
     * Saves the `_target_path` query parameter of a re-authentication link as the firewall's target path, when it is
     * a same-origin path of this application ({@see LoginTargetPolicy::sameOriginParts()}) and, with a policy, a page
     * the post-login guard allows (audit ZDD-I1: a crafted link cannot choose an API or JSON route even with
     * `login_target` off). It is stored as an absolute URL built from the path without the base URL (audit ZDD-L1:
     * Symfony adds the base URL again when it redirects). Anything else is ignored and the target already saved by
     * the firewall stays.
     */
    public static function keepTarget(Request $request, string $firewall, ?LoginTargetPolicy $policy = null): void
    {
        // all(): an array value is ignored, never a 400 (InputBag::get() refuses non-scalars).
        $target = $request->query->all()[self::TARGET_PARAMETER] ?? null;
        $parts  = is_string($target) && $request->hasSession() ? LoginTargetPolicy::sameOriginParts($target, $request) : null;
        if ($parts === null || ($policy instanceof LoginTargetPolicy && !$policy->allows($target, $request, $firewall))) {
            return;
        }
        [$path, $query] = $parts;
        $request->getSession()->set('_security.' . $firewall . '.target_path', $request->getUriForPath($path) . ($query !== null ? '?' . $query : ''));
    }
}
