<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;

use function preg_match;
use function str_contains;
use function str_starts_with;

/**
 * What a {@see SocialCallbackStepInterface} answers (1.46.0, REQ-ARCH-001 rule 3)
 * instead of a Symfony `Response`: sign a user in then redirect, or only redirect.
 */
final readonly class SocialCallbackResult
{
    /**
     * @param string $redirectUrl where the browser goes next: a path ('/…', not '//…') or an absolute http(s) URL, with no
     *                            backslash or control character. Build it from your routes, never from request input
     *                            (an absolute URL is not restricted to your host)
     *
     * @throws InvalidArgumentException when the URL is neither
     */
    private function __construct(
        public string $redirectUrl,
        public ?AuthUserRef $signIn = null,
        public bool $rememberMe = false,
    ) {
        $path = str_starts_with($redirectUrl, '/') && !str_starts_with($redirectUrl, '//');
        // Audit NV-I1: no backslash (browsers read `/\host` as `//host`) and no control character anywhere.
        if ((!$path && !str_starts_with($redirectUrl, 'https://') && !str_starts_with($redirectUrl, 'http://'))
            || str_contains($redirectUrl, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $redirectUrl) === 1
        ) {
            throw new InvalidArgumentException('A social callback redirect must be a path or an absolute http(s) URL.');
        }
    }

    /**
     * Sign the user in on the profile's firewall (the reference must carry your security user object), then redirect
     * (unless the sign-in answers itself, e.g. the MFA challenge).
     */
    public static function signIn(AuthUserRef $user, string $redirectUrl, bool $rememberMe = false): self
    {
        return new self($redirectUrl, $user, $rememberMe);
    }

    /** Only redirect. */
    public static function redirect(string $url): self
    {
        return new self($url);
    }
}
