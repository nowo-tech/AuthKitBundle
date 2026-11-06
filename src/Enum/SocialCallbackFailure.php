<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;

/**
 * Why a social callback did not reach {@see SocialCallbackHandlerInterface::handle()}.
 */
enum SocialCallbackFailure: string
{
    /** Social login is off for the profile, or the provider is unknown or disabled. */
    case Unavailable = 'unavailable';

    /** The provider answered `error=` (the user refused). */
    case ProviderDenied = 'provider_denied';

    /** Code exchange or profile fetch failed, or the handler threw (anything but an HTTP exception). */
    case Failed = 'failed';
}
