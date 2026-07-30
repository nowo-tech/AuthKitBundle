<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Whether OAuth/OIDC social login flows are active for a profile.
 */
enum SocialLoginMode: string
{
    case Disabled = 'disabled';
    case Enabled  = 'enabled';
}
