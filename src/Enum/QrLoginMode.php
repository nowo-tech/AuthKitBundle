<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Whether QR phone login is available for a profile.
 */
enum QrLoginMode: string
{
    case Disabled = 'disabled';
    case Enabled  = 'enabled';
}
