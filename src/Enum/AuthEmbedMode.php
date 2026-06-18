<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * How login/register can be embedded outside full-page routes.
 */
enum AuthEmbedMode: string
{
    case Disabled = 'disabled';
    case Dropdown = 'dropdown';
}
