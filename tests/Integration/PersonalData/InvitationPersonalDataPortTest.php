<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\PersonalData\DoctrineInvitationPersonalData;
use Nowo\AuthKitBundle\PersonalData\InvitationErasureResult;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataErasedEvent;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataSubject;
use Nowo\AuthKitBundle\Tests\Support\PersonalDataDatabase;
use Nowo\AuthKitBundle\Tests\Support\SingleManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_column;
use function array_keys;
use function bin2hex;
use function hash;
use function random_bytes;
use function sort;

/**
 * The invitation GDPR ports on a real database (SQLite here; PostgreSQL and MySQL in the CI job `real-databases`).
 */
final class InvitationPersonalDataPortTest extends TestCase
{
    private EntityManagerInterface $em;

    private DateTimeImmutable $now;

    /** @var list<array{bool, InvitationPersonalDataErasedEvent}> transaction active when dispatched, event */
    private array $events = [];

    protected function setUp(): void
    {
        $this->em  = PersonalDataDatabase::entityManager();
        $this->now = new DateTimeImmutable('2026-11-12 10:00:00');
    }

    public function testAnEmailSubjectDeletesTheInvitationsSentToIt(): void
    {
        $this->invite('Subject@Example.com', 'inviter@example.com');
        $this->invite('subject@example.com', 'inviter@example.com', 'other');
        $kept = $this->invite('other@example.com', 'inviter@example.com');

        $result = $this->port()->erase(InvitationPersonalDataSubject::fromEmail('  SUBJECT@example.com '));

        self::assertSame(['revoked' => 0, 'deleted' => 2, 'references_cleared' => 0], $result->toArray());
        self::assertSame([$kept], $this->ids());
        self::assertSame(0, $this->port()->erase(InvitationPersonalDataSubject::fromEmail('subject@example.com'))->total(), 'Idempotent.');
    }

    public function testAUserIdentifierSubjectRevokesOpenInvitationsAndClearsEveryReference(): void
    {
        $pending  = $this->invite('a@example.com', 'subject');
        $expired  = $this->invite('b@example.com', 'subject', ttl: -1);
        $accepted = $this->invite('c@example.com', 'subject', accept: 'c-user');
        $revoked  = $this->invite('d@example.com', 'boss', revokeBy: 'subject');
        $resent   = $this->invite('e@example.com', 'boss', sentBy: 'subject');
        $byOther  = $this->invite('f@example.com', 'boss', accept: 'subject');
        $other    = $this->invite('g@example.com', 'boss');

        $result = $this->port()->erase(InvitationPersonalDataSubject::fromUserIdentifier('subject'));

        // Revoked: pending + expired. References: invited_by x3, revoked_by x1, last_sent_by x1, accepted_by x1.
        self::assertSame(['revoked' => 2, 'deleted' => 0, 'references_cleared' => 6], $result->toArray());
        self::assertSame(8, $result->total());
        self::assertSame([$pending, $expired, $accepted, $revoked, $resent, $byOther, $other], $this->ids());
        $rows = $this->rows();
        foreach ($rows as $row) {
            foreach (DoctrineInvitationPersonalData::ACTOR_COLUMNS as $column) {
                self::assertNotSame('subject', $row[$column]);
            }
        }
        self::assertNotNull($rows[$pending]['revoked_at']);
        self::assertNotNull($rows[$expired]['revoked_at']);
        self::assertNull($rows[$accepted]['revoked_at'], 'An accepted invitation is not revoked.');
        self::assertNull($rows[$other]['revoked_at']);
        self::assertSame('boss', $rows[$other]['invited_by']);
        self::assertSame(0, $this->port()->erase(InvitationPersonalDataSubject::fromUserIdentifier('subject'))->total(), 'Idempotent.');
    }

    /** BUNDLES_FIX_RULES "Identifier columns": a case or accent variant is another person, whatever the collation. */
    public function testOnlyExactValuesMatch(): void
    {
        $upper = $this->invite('x@example.com', 'UserA');
        $plain = $this->invite('jose@example.com', 'boss');
        $exact = $this->invite('y@example.com', 'usera');

        $byId    = $this->port()->erase(InvitationPersonalDataSubject::fromUserIdentifier('usera'));
        $byEmail = $this->port()->erase(InvitationPersonalDataSubject::fromEmail('josé@example.com'));

        self::assertSame(['revoked' => 1, 'deleted' => 0, 'references_cleared' => 1], $byId->toArray());
        self::assertSame(0, $byEmail->total());
        $rows = $this->rows();
        self::assertSame('UserA', $rows[$upper]['invited_by']);
        self::assertNull($rows[$upper]['revoked_at']);
        self::assertArrayHasKey($plain, $rows);
        self::assertNull($rows[$exact]['invited_by']);
        self::assertSame(['received' => [], 'sent' => []], $this->port()->export(InvitationPersonalDataSubject::fromEmail('josé@example.com')));
        self::assertSame([], $this->port()->export(InvitationPersonalDataSubject::fromUserIdentifier('USERA'))['sent']);
    }

    public function testExportListsReceivedAndSentInvitationsWithoutSecrets(): void
    {
        $this->invite('subject@example.com', 'boss', message: 'Welcome', roles: ['ROLE_EDITOR']);
        $this->invite('subject@example.com', 'boss', 'other', accept: 'subject@example.com');
        $this->invite('friend@example.com', 'subject@example.com');
        $this->invite('pal@example.com', 'boss', sentBy: 'subject@example.com', revokeBy: 'boss');
        $this->invite('stranger@example.com', 'boss');

        $received = $this->port()->export(InvitationPersonalDataSubject::fromEmail('subject@example.com'));
        $sent     = $this->port()->export(InvitationPersonalDataSubject::fromUserIdentifier('subject@example.com'));

        self::assertSame([], $received['sent']);
        self::assertCount(2, $received['received']);
        $first = $received['received'][0];
        $keys  = array_keys($first);
        sort($keys);
        self::assertSame(['accepted_at', 'created_at', 'expires_at', 'locale', 'message', 'profile', 'revoked_at', 'roles', 'send_count', 'sent_at', 'status'], $keys);
        self::assertSame('default', $first['profile']);
        self::assertSame('pending', $first['status']);
        self::assertSame(['ROLE_EDITOR'], $first['roles']);
        self::assertSame('Welcome', $first['message']);
        self::assertSame('es', $first['locale']);
        self::assertSame(1, $first['send_count']);
        self::assertSame($this->now->format(DateTimeInterface::ATOM), $first['sent_at']);
        self::assertSame($this->now->modify('+7 days')->format(DateTimeInterface::ATOM), $first['expires_at']);
        self::assertNull($first['accepted_at']);
        self::assertSame('accepted', $received['received'][1]['status']);
        self::assertSame($this->now->format(DateTimeInterface::ATOM), $received['received'][1]['accepted_at']);

        self::assertSame([], $sent['received']);
        self::assertSame(['friend@example.com', 'pal@example.com'], array_column($sent['sent'], 'email'));
        self::assertSame(['pending', 'revoked'], array_column($sent['sent'], 'status'));
        self::assertNull($sent['sent'][0]['message']);
        self::assertNotNull($sent['sent'][1]['revoked_at']);
        $json = (string) json_encode([$received, $sent]);
        foreach ($this->rows() as $row) {
            self::assertStringNotContainsString((string) $row['token_hash'], $json);
            self::assertStringNotContainsString((string) $row['public_id'], $json);
        }
        self::assertStringNotContainsString('payload', $json);
    }

    public function testAnExpiredInvitationIsExportedAsExpired(): void
    {
        $this->invite('subject@example.com', 'boss', ttl: -1);

        self::assertSame('expired', $this->port()->export(InvitationPersonalDataSubject::fromEmail('subject@example.com'))['received'][0]['status']);
    }

    public function testTheEventFollowsItsOwnCommitOnly(): void
    {
        $this->invite('subject@example.com', 'boss');
        $this->invite('second@example.com', 'boss');
        $connection = $this->em->getConnection();

        $result = $this->port()->erase(InvitationPersonalDataSubject::fromEmail('subject@example.com'));

        self::assertCount(1, $this->events);
        self::assertFalse($this->events[0][0], 'Dispatched after the commit.');
        self::assertSame('subject@example.com', $this->events[0][1]->subject->value);
        self::assertSame($result, $this->events[0][1]->result);

        $connection->beginTransaction();
        $inside = $this->port()->erase(InvitationPersonalDataSubject::fromEmail('second@example.com'));
        self::assertSame(1, $inside->deleted);
        self::assertCount(1, $this->events, 'Inside the caller transaction no event.');
        $connection->rollBack();
        self::assertCount(1, $this->ids(), 'The caller rolled back: the row is back.');

        $this->port()->erase(InvitationPersonalDataSubject::fromEmail('nobody@example.com'));
        self::assertCount(1, $this->events, 'Nothing changed, no event.');
    }

    public function testWithoutTableOrMappingNothingHappens(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE auth_kit_invitation');
        $subject = InvitationPersonalDataSubject::fromUserIdentifier('subject');

        self::assertEquals(new InvitationErasureResult(), $this->port()->erase($subject));
        self::assertSame(['received' => [], 'sent' => []], $this->port()->export($subject));

        $unmapped = new DoctrineInvitationPersonalData($this->createStub(ManagerRegistry::class));
        self::assertSame(0, $unmapped->erase($subject)->total());
        self::assertSame(['received' => [], 'sent' => []], $unmapped->export($subject));
    }

    public function testMoreRowsThanOneBatch(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        for ($i = 0; $i < 1203; ++$i) {
            $this->invite('bulk' . $i . '@example.com', $i % 2 === 0 ? 'subject' : 'Subject', flush: false);
            if ($i % 200 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();
        $connection->commit();

        self::assertSame(['revoked' => 602, 'deleted' => 0, 'references_cleared' => 602], $this->port()->erase(InvitationPersonalDataSubject::fromUserIdentifier('subject'))->toArray());
        self::assertSame(601, (int) $connection->fetchOne("SELECT COUNT(*) FROM auth_kit_invitation WHERE invited_by = 'Subject' AND revoked_at IS NULL"));
    }

    /** FrankenPHP worker mode: one service instance, two people, many runs, no leak, flat memory. */
    public function testWorkerModeRunsKeepNoState(): void
    {
        $this->invite('alice@example.com', 'bob');
        $this->invite('bob@example.com', 'alice');
        $port      = $this->port();
        $alice     = InvitationPersonalDataSubject::fromEmail('alice@example.com');
        $bob       = InvitationPersonalDataSubject::fromUserIdentifier('bob');
        $expectedA = $port->export($alice);
        $expectedB = $port->export($bob);
        self::assertCount(1, $expectedA['received']);
        self::assertSame(['alice@example.com'], array_column($expectedB['sent'], 'email'));
        $runs   = PersonalDataDatabase::isRealServer() ? 50 : 1000;
        $before = 0;
        for ($i = 0; $i < $runs; ++$i) {
            if ($i === 10) {
                $before = memory_get_usage();
            }
            self::assertSame($expectedA, $port->export($alice));
            self::assertSame($expectedB, $port->export($bob));
            self::assertSame(0, $port->erase(InvitationPersonalDataSubject::fromUserIdentifier('ghost-' . $i))->total());
            self::assertSame(0, $port->erase(InvitationPersonalDataSubject::fromEmail('ghost-' . $i . '@example.com'))->total());
        }
        self::assertLessThan(1024 * 1024, memory_get_usage() - $before, 'Memory stays flat.');
        self::assertSame([], $this->events);
    }

    private function port(): DoctrineInvitationPersonalData
    {
        $doctrine   = new SingleManagerRegistry($this->em);
        $dispatcher = new EventDispatcher();
        $connection = $this->em->getConnection();
        $dispatcher->addListener(InvitationPersonalDataErasedEvent::class, function (InvitationPersonalDataErasedEvent $event) use ($connection): void {
            $this->events[] = [$connection->isTransactionActive(), $event];
        });
        $clock = new MockClock($this->now);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new DoctrineInvitationPersonalData($doctrine, $clock, $dispatcher);
    }

    /**
     * @param list<string> $roles
     */
    private function invite(string $email, ?string $by, string $profile = 'default', int $ttl = 7, ?string $accept = null, ?string $revokeBy = null, ?string $sentBy = null, ?string $message = null, array $roles = [], bool $flush = true): int
    {
        $invitation = new Invitation($profile, $email, $by, 7, $this->now);
        $invitation->issue(hash('sha256', bin2hex(random_bytes(16))), $ttl, $this->now, $sentBy);
        $invitation->setLocale('es')->setMessage($message)->setRoles($roles);
        if ($accept !== null) {
            $invitation->markAccepted($accept, $this->now);
        }
        if ($revokeBy !== null) {
            $invitation->revoke($revokeBy, $this->now);
        }
        $this->em->persist($invitation);
        if (!$flush) {
            return 0;
        }
        $this->em->flush();

        return (int) $invitation->getId();
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM auth_kit_invitation ORDER BY id'));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM auth_kit_invitation ORDER BY id') as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }
}
