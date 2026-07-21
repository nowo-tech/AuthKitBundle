<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Whether passwordless magic-login (email link) is available.
 */
enum MagicLoginMode: string
{
    case Disabled = 'disabled';
    case Enabled  = 'enabled';
}
