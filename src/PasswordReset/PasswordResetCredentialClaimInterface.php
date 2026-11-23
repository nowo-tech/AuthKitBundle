<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

/**
 * 2.2.0 (REQ-RUNTIME-003): the single-use claim of a reset credential. A
 * {@see PasswordResetTokenManagerInterface} that also implements it lets {@see PasswordResetCompleter} store a new
 * password only for the request that actually used up the credential: two completions of one link or code sent at
 * the same moment store one password, and the other one is refused ({@see PasswordResetLinkUsedException}).
 *
 * The bundled managers implement it (user entity: a conditional UPDATE on the stored value this request read; table:
 * the DELETE of the user's rows, which only one transaction can do). A host manager that does not implement it keeps
 * the behaviour of 2.1 (the credential is cleared, the last completion wins).
 */
interface PasswordResetCredentialClaimInterface
{
    /**
     * Clears the user's reset credential, but only when it is still the one this request read. Runs inside the
     * caller's unit of work (it never commits on its own when one is open).
     *
     * @return bool false when another request used, cleared or replaced the credential meanwhile (nothing written)
     */
    public function claimForUser(object $user): bool;
}
