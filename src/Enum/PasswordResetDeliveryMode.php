<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * How the reset credential is delivered to the user.
 *
 * - link: URL with token (email, magic link, push deep link).
 * - code: short OTP/code (SMS, email code, authenticator app).
 * - both: notifier receives link URL and plain code.
 */
enum PasswordResetDeliveryMode: string
{
    case Link = 'link';
    case Code = 'code';
    case Both = 'both';
}
