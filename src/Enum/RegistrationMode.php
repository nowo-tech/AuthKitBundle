<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Controls when self-service registration is allowed.
 */
enum RegistrationMode: string
{
    case Disabled      = 'disabled';
    case FirstUserOnly = 'first_user_only';
    case Always        = 'always';
}
