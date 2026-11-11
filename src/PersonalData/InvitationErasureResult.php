<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * What {@see InvitationPersonalDataEraserInterface::erase()} changed (1.45): open invitations of the actor revoked
 * (their links stop working), invitations addressed to the e-mail deleted, and actor references set to null (one per
 * column and row).
 */
final readonly class InvitationErasureResult
{
    public function __construct(
        public int $revoked = 0,
        public int $deleted = 0,
        public int $referencesCleared = 0,
    ) {
    }

    /** @return array{revoked: int, deleted: int, references_cleared: int} */
    public function toArray(): array
    {
        return ['revoked' => $this->revoked, 'deleted' => $this->deleted, 'references_cleared' => $this->referencesCleared];
    }

    public function total(): int
    {
        return $this->revoked + $this->deleted + $this->referencesCleared;
    }

    public function with(self $other): self
    {
        return new self($this->revoked + $other->revoked, $this->deleted + $other->deleted, $this->referencesCleared + $other->referencesCleared);
    }
}
