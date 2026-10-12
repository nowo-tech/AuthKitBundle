<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Locale;

/**
 * Default resolver: reads {@see PreferredLocaleAwareInterface::getPreferredLocale()} when the user implements it.
 */
final class PreferredLocaleAwareRecipientLocaleResolver implements RecipientLocaleResolverInterface
{
    public function resolve(object $user): ?string
    {
        return $user instanceof PreferredLocaleAwareInterface ? $user->getPreferredLocale() : null;
    }
}
