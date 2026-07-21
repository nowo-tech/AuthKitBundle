<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Behaviour of bare (no {_locale}) auth URLs when {@see LocaleInPathMode::Both}.
 */
enum UnlocalizedLocaleMode: string
{
    /** Render the page and apply locale.default on the request. */
    case Serve = 'serve';

    /** HTTP redirect to the canonical /{locale}/… URL. */
    case Redirect = 'redirect';
}
