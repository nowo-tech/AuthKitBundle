<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Nowo\AuthKitBundle\DependencyInjection\MfaConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;

use function in_array;
use function is_object;
use function is_string;
use function method_exists;

/**
 * First factor of a sign-in (1.34), kept on the main request: AuthKit's own `Security::login()` calls name it
 * (`social`, `qr`, `registration`, `invitation`, `login_link`), firewall authenticators are recognised by class
 * (`form_login`, `login_link`, `remember_me`), anything else is `default`.
 */
final class MfaFirstFactor
{
    public const REQUEST_ATTRIBUTE = '_nowo_auth_kit_first_factor';

    /** 2.7.0: the rule of this sign-in's provider credential (required | exempt), over `mfa.first_factors.social`. */
    public const RULE_ATTRIBUTE = '_nowo_auth_kit_first_factor_rule';

    public const REMEMBER_ME = 'remember_me';

    public const DEFAULT = 'default';

    /** 2.4.0: a passkey sign-in (`WebauthnLoginController`). */
    public const WEBAUTHN = 'webauthn';

    /**
     * Marks the request before an AuthKit controller calls `Security::login()`.
     */
    public static function mark(Request $request, string $factor): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $factor);
    }

    /**
     * 2.7.0: the rule a social provider credential sets for this sign-in; null removes it (the profile's rule applies).
     */
    public static function markRule(Request $request, ?string $rule): void
    {
        if ($rule === null) {
            $request->attributes->remove(self::RULE_ATTRIBUTE);

            return;
        }
        $request->attributes->set(self::RULE_ATTRIBUTE, $rule);
    }

    /**
     * The credential rule marked on the request: `required` or `exempt`, else null (anything else is ignored).
     */
    public static function ruleOf(?Request $request): ?string
    {
        $rule = $request?->attributes->get(self::RULE_ATTRIBUTE);

        return in_array($rule, [MfaConfiguration::RULE_REQUIRED, MfaConfiguration::RULE_EXEMPT], true) ? $rule : null;
    }

    public static function of(?Request $request): string
    {
        $factor = $request?->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($factor) && $factor !== '' ? $factor : self::DEFAULT;
    }

    /**
     * Key of a firewall authenticator (debug wrappers unwrapped), or null for an unknown one.
     */
    public static function fromAuthenticator(object $authenticator): ?string
    {
        if (method_exists($authenticator, 'getAuthenticator')) {
            $inner = $authenticator->getAuthenticator();
            if (is_object($inner)) {
                $authenticator = $inner;
            }
        }

        return match (true) {
            $authenticator instanceof FormLoginAuthenticator  => 'form_login',
            $authenticator instanceof LoginLinkAuthenticator  => 'login_link',
            $authenticator instanceof RememberMeAuthenticator => self::REMEMBER_ME,
            default                                           => null,
        };
    }
}
