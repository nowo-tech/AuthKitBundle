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

    /** The provider answered `error=` (the user refused). The request is not proven to come from the provider. */
    case ProviderDenied = 'provider_denied';

    /** Missing code or state, or a state that does not match this session's pending authorization. */
    case InvalidState = 'invalid_state';

    /** Code exchange or profile fetch failed, or the handler threw a RuntimeException. */
    case Failed = 'failed';
}
