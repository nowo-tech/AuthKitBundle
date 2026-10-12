<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Locale;

/**
 * Resolves the preferred locale of a notification recipient (an AuthKit user object).
 *
 * Replace the default implementation with `nowo_auth_kit.recipient_locale.resolver` (service id).
 * Implementations must be stateless (FrankenPHP worker mode).
 */
interface RecipientLocaleResolverInterface
{
    /**
     * @return string|null a locale code, or null when the recipient has no preference
     */
    public function resolve(object $user): ?string;
}
