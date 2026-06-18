<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Whether password reset flows are exposed.
 */
enum PasswordResetMode: string
{
    case Disabled = 'disabled';
    case Enabled  = 'enabled';
}
