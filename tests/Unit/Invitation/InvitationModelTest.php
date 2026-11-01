<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Invitation\Ui\InvitationRow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function str_repeat;

/**
 * Invitation entity (derived status, single link, bounded fields), tokens (base64url, SHA-256, constant-time
 * comparison) and the value objects that never carry the token hash.
 */
final class InvitationModelTest extends TestCase
{
    public function testStatusIsDerivedFromTheDates(): void
    {
        $now        = new DateTimeImmutable('2026-11-01 10:00:00');
        $invitation = new Invitation('default', '  Ana@Example.COM ', 'admin@example.com', 3, $now);

        self::assertSame('ana@example.com', $invitation->getEmail());
        self::assertSame('default', $invitation->getProfile());
        self::assertMatchesRegularExpression('/^' . Invitation::PUBLIC_ID_REQUIREMENT . '$/', $invitation->getPublicId());
        self::assertNull($invitation->getId());
        self::assertSame($now, $invitation->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-11-04 10:00:00'), $invitation->getExpiresAt());
        self::assertSame(InvitationStatus::Pending, $invitation->status($now));
        self::assertSame(InvitationStatus::Expired, $invitation->status(new DateTimeImmutable('2026-11-04 10:00:00')));
        self::assertTrue($invitation->isOpen());

        $invitation->issue('hash-1', 7, $now);
        self::assertSame('hash-1', $invitation->getTokenHash());
        self::assertSame(1, $invitation->getSendCount());
        self::assertSame(7, $invitation->getTtlDays());
        self::assertSame($now, $invitation->getSentAt());

        $invitation->markAccepted('ana@example.com', $now);
        self::assertSame(InvitationStatus::Accepted, $invitation->status($now));
        self::assertSame('ana@example.com', $invitation->getAcceptedBy());
        self::assertSame($now, $invitation->getAcceptedAt());
        self::assertFalse($invitation->isOpen());

        $revoked = new Invitation('default', 'bob@example.com', null, 1, $now);
        $revoked->revoke('', $now);
        self::assertNull($revoked->getRevokedBy());
        self::assertSame($now, $revoked->getRevokedAt());
        self::assertSame(InvitationStatus::Revoked, $revoked->status($now));
        self::assertNull($revoked->getInvitedBy());
    }

    public function testFieldsAreNormalisedAndBounded(): void
    {
        $invitation = (new Invitation('default', 'a@example.com', str_repeat('x', 300), 1, new DateTimeImmutable()))
            ->setRoles(['ROLE_A', 'ROLE_A', 'ROLE_B'])
            ->setPayload(['team' => 'blue'])
            ->setMessage('  ' . str_repeat('m', 600) . '  ')
            ->setLocale('');

        self::assertSame(['ROLE_A', 'ROLE_B'], $invitation->getRoles());
        self::assertSame(['team' => 'blue'], $invitation->getPayload());
        self::assertSame(Invitation::MESSAGE_MAX_LENGTH, mb_strlen((string) $invitation->getMessage()));
        self::assertNull($invitation->getLocale());
        self::assertSame(Invitation::IDENTIFIER_MAX_LENGTH, mb_strlen((string) $invitation->getInvitedBy()));
        self::assertNull($invitation->setMessage('   ')->getMessage());
        self::assertSame('es', $invitation->setLocale('es')->getLocale());
    }

    public function testTokensAreRandomAndOnlyTheirHashIsComparedInConstantTime(): void
    {
        $tokens           = new InvitationTokens();
        [$token, $hash]   = $tokens->generate();
        [$other, $other2] = $tokens->generate();

        self::assertMatchesRegularExpression('/^' . InvitationTokens::PATTERN . '$/', $token);
        self::assertNotSame($token, $other);
        self::assertSame(hash('sha256', $token), $hash);
        self::assertSame($hash, $tokens->hash($token));
        self::assertTrue($tokens->matchesHash($hash, $hash));
        self::assertFalse($tokens->matchesHash($other2, $hash));
        self::assertFalse($tokens->matchesHash('', ''));
        self::assertFalse($tokens->matchesHash($hash, ''));
    }

    public function testSnapshotRowAndOutcomeCarryNoTokenHash(): void
    {
        $now        = new DateTimeImmutable('2026-11-01 10:00:00');
        $invitation = (new Invitation('default', 'ana@example.com', 'admin@example.com', 7, $now))->setRoles(['ROLE_EDITOR']);
        $invitation->issue('secret-hash', 7, $now);

        $snapshot = InvitationSnapshot::of($invitation, $now);
        foreach ((new ReflectionClass($snapshot))->getProperties() as $property) {
            self::assertNotSame('secret-hash', $property->getValue($snapshot));
        }
        self::assertSame(InvitationStatus::Pending, $snapshot->status);

        $row = InvitationRow::of($snapshot);
        self::assertSame('ROLE_EDITOR', $row->roles);
        self::assertSame('admin@example.com', $row->invitedBy);

        self::assertSame(InvitationOutcome::SENT, InvitationOutcome::sent($snapshot)->status);
        self::assertSame(InvitationOutcome::NOT_DELIVERED, InvitationOutcome::notDelivered($snapshot)->status);
        $link = InvitationOutcome::link($snapshot, 'https://app.example.com/invitation/t');
        self::assertSame('https://app.example.com/invitation/t', $link->url);
        $refused = InvitationOutcome::refused(InvitationOutcome::RATE_LIMITED, null, $now);
        self::assertNull($refused->invitation);
        self::assertSame($now, $refused->retryAfter);
    }

    public function testStatusLabels(): void
    {
        self::assertSame('invitation.status.expired', InvitationStatus::Expired->labelKey());
        self::assertTrue(InvitationStatus::Expired->isOpen());
        self::assertFalse(InvitationStatus::Accepted->isOpen());
    }
}
