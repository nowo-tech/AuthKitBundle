<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

/**
 * The user turned the second factor on (enrolment confirmed). Dispatched after the write.
 */
final class MfaEnabledEvent extends MfaEvent
{
}
