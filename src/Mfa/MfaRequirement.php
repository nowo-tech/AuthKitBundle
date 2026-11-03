<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

/**
 * Whether a user must have a second factor (1.34).
 */
enum MfaRequirement: string
{
    /** MFA is offered; the user may enrol. */
    case Optional = 'optional';

    /** MFA is required: grace period, then forced enrolment at sign-in. */
    case Required = 'required';
}
