<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

/**
 * The account reached `mfa.rate_limit.account` failures: codes are refused until the window ends.
 */
final class MfaLockedOutEvent extends MfaEvent
{
}
