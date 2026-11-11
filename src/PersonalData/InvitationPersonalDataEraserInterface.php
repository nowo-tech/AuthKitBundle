<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * GDPR erasure port of the invitation table (1.45). For an e-mail subject it deletes the invitations addressed to it;
 * for a user-identifier subject it revokes the actor's open invitations (their links stop working) and sets the
 * actor's identifier to null in `invited_by`, `last_sent_by`, `revoked_by` and `accepted_by` of the remaining rows.
 *
 * Idempotent; exact matching (the SQL comparison only pre-filters, every row is re-checked with `===`). Joins the
 * caller's open transaction, else runs in its own and dispatches {@see InvitationPersonalDataErasedEvent} after the
 * commit (never inside the caller's transaction: the returned result is the outcome then).
 */
interface InvitationPersonalDataEraserInterface
{
    public function erase(InvitationPersonalDataSubject $subject): InvitationErasureResult;
}
