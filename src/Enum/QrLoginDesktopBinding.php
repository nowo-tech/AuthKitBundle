<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Desktop binding strictness for QR login challenge completion.
 */
enum QrLoginDesktopBinding: string
{
    case Off    = 'off';
    case Soft   = 'soft';
    case Strict = 'strict';
}
