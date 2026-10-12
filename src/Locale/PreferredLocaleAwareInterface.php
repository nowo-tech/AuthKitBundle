<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Locale;

/**
 * Optional user-entity SPI: the account's preferred UI / mail locale.
 *
 * AuthKit uses it to localize the notification context (password reset, magic login, new device)
 * and the links it generates for that recipient. Return null when the user has no preference.
 */
interface PreferredLocaleAwareInterface
{
    public function getPreferredLocale(): ?string;
}
