<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * What an inviter asks for ({@see InvitationManager::invite()}): the e-mail, the roles to grant (each must be in
 * `invitations.roles` and held by the inviter), the validity, an optional personal message, the locale of the e-mail
 * and the host payload (pre-filled registration fields, data for the {@see InvitationGrantInterface} services).
 * `returnLink` (1.33): no e-mail is sent, the outcome carries the link for a host that sends its own mail.
 */
final readonly class InvitationRequest
{
    /**
     * @param list<string> $roles
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $email,
        public array $roles = [],
        public ?int $ttlDays = null,
        public ?string $message = null,
        public ?string $locale = null,
        public array $payload = [],
        public bool $returnLink = false,
    ) {
    }
}
