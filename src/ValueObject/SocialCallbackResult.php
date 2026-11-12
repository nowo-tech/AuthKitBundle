<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;

use function str_starts_with;

/**
 * What a {@see SocialCallbackStepInterface} answers (1.46.0, REQ-ARCH-001 rule 3)
 * instead of a Symfony `Response`: sign a user in then redirect, or only redirect.
 */
final readonly class SocialCallbackResult
{
    /**
     * @param string $redirectUrl where the browser goes next: a path ('/…', not '//…') or an absolute http(s) URL
     *
     * @throws InvalidArgumentException when the URL is neither
     */
    private function __construct(
        public string $redirectUrl,
        public ?AuthUserRef $signIn = null,
        public bool $rememberMe = false,
    ) {
        $path = str_starts_with($redirectUrl, '/') && !str_starts_with($redirectUrl, '//');
        if (!$path && !str_starts_with($redirectUrl, 'https://') && !str_starts_with($redirectUrl, 'http://')) {
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
