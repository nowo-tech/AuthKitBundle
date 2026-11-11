<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * GDPR export port of the invitation table (1.45): `received` (invitations sent to an e-mail subject) and `sent`
 * (invitations a user-identifier subject created or last sent). JSON-serialisable; never the token hash, the public id
 * or the host payload.
 *
 * Each entry: `profile`, `status` (`pending`, `expired`, `accepted`, `revoked`), `roles`, `message`, `locale`,
 * `created_at`, `sent_at`, `expires_at`, `send_count`, `accepted_at`, `revoked_at` (ATOM strings or null). A `sent`
 * entry never names the invitee (another person's data).
 */
interface InvitationPersonalDataExporterInterface
{
    /**
     * @return array{received: list<array<string, mixed>>, sent: list<array<string, mixed>>}
     */
    public function export(InvitationPersonalDataSubject $subject): array;
}
