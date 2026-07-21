<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * How Auth Kit routes expose {_locale} in the URL path.
 */
enum LocaleInPathMode: string
{
    /** Only bare paths: /login, /register, … */
    case Never = 'never';

    /** Only localized paths: /{_locale}/login, … */
    case Always = 'always';

    /** Both bare and localized paths. */
    case Both = 'both';

    public function usesLocalePrefix(): bool
    {
        return $this !== self::Never;
    }

    public function registersLocalizedRoutes(): bool
    {
        return $this === self::Always || $this === self::Both;
    }
}
