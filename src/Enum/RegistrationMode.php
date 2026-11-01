<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Controls when self-service registration is allowed.
 *
 * - `disabled`: closed — no self-registration and no invitation acceptance.
 * - `first_user_only`: only while the user table is empty (accepting an invitation is still allowed when
 *   `invitations.mode` is `enabled`).
 * - `always`: open registration (invitations, when enabled, work as well).
 * - `invite_only` (1.32): the open registration page is closed; accounts are created only by accepting an invitation
 *   (`invitations.mode: enabled` is required).
 */
enum RegistrationMode: string
{
    case Disabled      = 'disabled';
    case FirstUserOnly = 'first_user_only';
    case Always        = 'always';
    case InviteOnly    = 'invite_only';
}
