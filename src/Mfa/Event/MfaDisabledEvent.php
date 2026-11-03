<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

/**
 * The user turned the second factor off (own action, step-up code checked). Dispatched after the write.
 */
final class MfaDisabledEvent extends MfaEvent
{
}
