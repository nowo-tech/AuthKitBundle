<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Config;

use function in_array;

/**
 * Resolves whether persistent remember-me cookies should be enabled.
 */
final class RememberMeConfigResolver
{
    /**
     * @param list<string> $loginFields raw login_fields tokens from configuration
     */
    public static function isFirewallEnabled(bool $rememberMeEnabled, array $loginFields): bool
    {
        if ($rememberMeEnabled) {
            return true;
        }

        return in_array('remember_me', $loginFields, true);
    }

    /**
     * @param list<array{name: string, type: string, property: ?string, hash: bool, required: bool, security_name: ?string}> $normalizedLoginFields
     */
    public static function isFirewallEnabledForNormalizedFields(bool $rememberMeEnabled, array $normalizedLoginFields): bool
    {
        if ($rememberMeEnabled) {
            return true;
        }

        foreach ($normalizedLoginFields as $field) {
            if ($field['name'] === '_remember_me') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $loginFields
     *
     * @return list<string>
     */
    public static function ensureLoginField(array $loginFields, bool $rememberMeEnabled): array
    {
        if (!$rememberMeEnabled || in_array('remember_me', $loginFields, true)) {
            return $loginFields;
        }

        $loginFields[] = 'remember_me';

        return $loginFields;
    }
}
