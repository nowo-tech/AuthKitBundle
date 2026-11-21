<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotFoundException;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotManagedException;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\AuthKitBundle\Tests\Database\Support\HostLoadedEntitiesTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\HostModeNotices;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * 1.55.0 (Q-W2-1, REQ-ARCH-001 rule 6) with `doctrine.private_entity_manager: true`: an AuthKit row the application
 * loaded through its own entity manager (a host repository, a route value resolver, `getReference()`) and handed to an
 * AuthKit service is written as the existing row (re-loaded by id in AuthKit's private manager), never inserted again.
 * A row deleted meanwhile fails with a domain exception, the application's manager is never flushed, cleared or
 * detached by the bundle, and its pending changes stay pending.
 *
 * Runs on SQLite, and on the server of AUTH_KIT_LOAD_DATABASE_URL when it is set (a throwaway database: the setup drops
 * everything in it).
 */
final class HostLoadedEntitiesTest extends KernelTestCase
{
    use HostModeNotices;

    protected static function getKernelClass(): string
    {
        return HostLoadedEntitiesTestKernel::class;
    }

    protected function setUp(): void
    {
        $url = DatabaseTestKernel::databaseUrl();
        if ($url !== null) {
            $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            if (preg_match('/(?:^|_)(?:test|race|load|throwaway)(?:_|$)/', $database) !== 1) {
                throw new RuntimeException(sprintf('Refusing to use database "%s": the setup drops everything in it.', $database));
            }
        }
        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $tool = new SchemaTool($this->host());
        $tool->dropDatabase();
        $tool->createSchema($this->host()->getMetadataFactory()->getAllMetadata());
    }

    public function testResendOfAnInvitationLoadedByTheApplicationUpdatesTheRow(): void
    {
        $id     = $this->invitation('ada@example.com');
        $before = $this->column('token_hash', $id);
        $loaded = $this->hostInvitation($id);

        $outcome = $this->manager()->resend(null, $loaded);

        self::assertSame(InvitationOutcome::SENT, $outcome->status);
        self::assertSame(1, $this->invitationRows(), 'no second row');
        self::assertNotSame($before, $this->column('token_hash', $id), 'the new link is stored on the existing row');
        self::assertTrue($this->host()->contains($loaded), 'the application still holds its own instance');
        self::assertSame($before, $loaded->getTokenHash(), 'the application\'s instance is not written to');
        // A later flush of the application's manager does not write the stale copy back.
        $this->host()->flush();
        self::assertNotSame($before, $this->column('token_hash', $id));
    }

    public function testRevokeOfAnInvitationReferenceOfTheApplicationRevokesTheRow(): void
    {
        $id        = $this->invitation('bob@example.com');
        $reference = $this->host()->getReference(Invitation::class, $id);
        self::assertInstanceOf(Invitation::class, $reference);

        $outcome = $this->manager()->revoke(null, $reference);

        self::assertSame(InvitationOutcome::REVOKED, $outcome->status);
        self::assertSame(1, $this->invitationRows());
        self::assertNotNull($this->column('revoked_at', $id));
    }

    public function testResendAndRevokeReadTheStoredRowNotTheApplicationsStaleCopy(): void
    {
        $id     = $this->invitation('carol@example.com');
        $loaded = $this->hostInvitation($id);
        $this->connection()->executeStatement('UPDATE auth_kit_invitation SET revoked_at = created_at WHERE id = ' . $id);

        self::assertSame(InvitationOutcome::NOT_OPEN, $this->manager()->resend(null, $loaded)->status);
        self::assertSame(InvitationOutcome::NOT_OPEN, $this->manager()->revoke(null, $loaded)->status);
        self::assertSame(1, $this->invitationRows());
    }

    public function testAnExistingAccountAcceptsAnInvitationLoadedByTheApplication(): void
    {
        $user = (new KernelUser())->setEmail('dave@example.com')->setPassword('unused-password');
        $this->host()->persist($user);
        $this->host()->flush();
        $id = $this->invitation('dave@example.com');

        $acceptance = $this->acceptor()->acceptExisting($user, $this->hostInvitation($id), 'dave@example.com');

        self::assertSame(InvitationAcceptance::ACCEPTED, $acceptance->status);
        self::assertSame(1, $this->invitationRows());
        self::assertSame('dave@example.com', $this->column('accepted_by', $id));
    }

    public function testTheFormAcceptanceOfAnInvitationLoadedByTheApplicationCreatesTheAccount(): void
    {
        $id     = $this->invitation('erin@example.com');
        $loaded = $this->hostInvitation($id);

        $acceptance = $this->acceptor()->attempt($loaded, $this->hash('erin@example.com'), ['email' => 'erin@example.com', 'password' => 'Erin-password-2026']);

        self::assertSame(InvitationAcceptance::ACCEPTED, $acceptance->status);
        self::assertSame(1, $this->invitationRows());
        self::assertSame('erin@example.com', $this->column('accepted_by', $id));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'erin@example.com'"));
    }

    public function testQrLoginChallengesLoadedByTheApplicationAreDeniedAndConsumed(): void
    {
        $qr     = $this->qr();
        $denied = $this->challenge();
        $qr->deny($this->hostChallenge($denied));
        self::assertSame(QrLoginChallengeStatus::Denied->value, $this->challengeStatus($denied));

        $approved = $this->challenge();
        $user     = (new KernelUser())->setEmail('frank@example.com')->setPassword('unused-password');
        $qr->approve($this->hostChallenge($approved), $user, null);
        self::assertSame(QrLoginChallengeStatus::Approved->value, $this->challengeStatus($approved));
        $this->host()->clear();
        $qr->consume($this->hostChallenge($approved), $user);
        self::assertSame(QrLoginChallengeStatus::Consumed->value, $this->challengeStatus($approved));
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM auth_kit_qr_login_challenge'));
    }

    public function testAnExpiredQrLoginChallengeLoadedByTheApplicationIsMarkedExpired(): void
    {
        $id = $this->challenge();
        $this->connection()->executeStatement("UPDATE auth_kit_qr_login_challenge SET expires_at = '2000-01-01 00:00:00' WHERE id = '" . $id . "'");

        self::assertTrue($this->qr()->isExpiredOrInvalid($this->hostChallenge($id)));
        self::assertSame(QrLoginChallengeStatus::Expired->value, $this->challengeStatus($id));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM auth_kit_qr_login_challenge'));
    }

    public function testARowDeletedMeanwhileFailsWithADomainException(): void
    {
        $id     = $this->invitation('gina@example.com');
        $loaded = $this->hostInvitation($id);
        $this->connection()->executeStatement('DELETE FROM auth_kit_invitation WHERE id = ' . $id);

        try {
            $this->manager()->resend(null, $loaded);
            self::fail('A deleted invitation must not be written again.');
        } catch (AuthKitEntityNotFoundException $exception) {
            self::assertSame(Invitation::class, $exception->entityClass);
            self::assertSame((string) $id, $exception->id);
            self::assertStringContainsString('no longer exists', $exception->getMessage());
        }
        self::assertSame(0, $this->invitationRows(), 'nothing inserted');

        $challenge = $this->challenge();
        $hostCopy  = $this->hostChallenge($challenge);
        $this->connection()->executeStatement("DELETE FROM auth_kit_qr_login_challenge WHERE id = '" . $challenge . "'");
        $this->expectException(AuthKitEntityNotFoundException::class);
        $this->qr()->deny($hostCopy);
    }

    public function testSavingAnInstanceThePrivateManagerDoesNotHoldIsRefused(): void
    {
        $id     = $this->invitation('hugo@example.com');
        $loaded = $this->hostInvitation($id);
        $loaded->setMessage('changed by the application');

        try {
            $this->invitations()->save($loaded);
            self::fail('A foreign instance must never be persisted as new.');
        } catch (AuthKitEntityNotManagedException $exception) {
            self::assertSame(Invitation::class, $exception->entityClass);
        }
        self::assertSame(1, $this->invitationRows());

        $challenge = $this->challenge();
        $this->expectException(AuthKitEntityNotManagedException::class);
        $this->challenges()->save($this->hostChallenge($challenge));
    }

    public function testSavingANewInvitationOrChallengeStillInserts(): void
    {
        $new = new Invitation('default', 'ivy@example.com', null, 7, new DateTimeImmutable());
        self::assertSame($new, $this->invitations()->adopt($new), 'a new invitation is returned as it is');
        $this->invitations()->save($new);
        self::assertSame(1, $this->invitationRows());
        self::assertSame($new, $this->invitations()->adopt($new), 'an instance the manager holds is returned as it is');

        $created  = $this->qr()->create(Request::create('/login/qr'))['challenge'];
        $assigned = new QrLoginChallenge('00000000-0000-4000-8000-000000000001', 'abcd1234', $created->getDesktopCookieHash(), 'ip', 'ua', 'label', 'token', new DateTimeImmutable('+5 minutes'));
        $this->challenges()->save($assigned);
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM auth_kit_qr_login_challenge'), 'a new challenge with an assigned id is inserted');
    }

    public function testTheApplicationsPendingChangesStayPendingAndItsManagerIsNeverTouched(): void
    {
        $id     = $this->invitation('jane@example.com');
        $loaded = $this->hostInvitation($id);
        $this->host()->persist((new KernelUser())->setEmail('pending@example.com')->setPassword('pending-password'));
        $loaded->setMessage('the application\'s own pending change');

        self::assertSame(InvitationOutcome::SENT, $this->manager()->resend(null, $loaded)->status);
        $challenge = $this->challenge();
        $this->qr()->deny($this->hostChallenge($challenge));
        self::assertSame(InvitationOutcome::REVOKED, $this->manager()->revoke(null, $loaded)->status);

        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"), 'not flushed by AuthKit');
        self::assertCount(1, $this->host()->getUnitOfWork()->getScheduledEntityInsertions(), 'still scheduled');
        self::assertTrue($this->host()->contains($loaded), 'not detached');
        self::assertSame('the application\'s own pending change', $loaded->getMessage(), 'not refreshed');
        self::assertNull($this->column('message', $id), 'its change is not written by AuthKit');
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());

        $this->host()->flush();
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"));
        self::assertSame('the application\'s own pending change', $this->column('message', $id));
        self::assertNotNull($this->column('revoked_at', $id), 'the application\'s flush keeps AuthKit\'s revocation');
    }

    private function invitation(string $email): int
    {
        $now        = new DateTimeImmutable();
        $invitation = new Invitation('default', $email, null, 7, $now);
        $invitation->issue($this->hash($email), 7, $now);
        $this->invitations()->save($invitation);
        $id = $invitation->getId();
        self::assertNotNull($id);
        $this->nextRequest();

        return $id;
    }

    private function challenge(): string
    {
        $id = $this->qr()->create(Request::create('/login/qr'))['challenge']->getId();
        $this->nextRequest();

        return $id;
    }

    /**
     * The private manager is dropped at every unit-of-work boundary: start from an empty one, as a new request does.
     */
    private function nextRequest(): void
    {
        $private = self::getContainer()->get(AuthKitEntityManager::class);
        assert($private instanceof AuthKitEntityManager);
        $private->live()->clear();
    }

    private function hash(string $email): string
    {
        return (new InvitationTokens())->hash('token-' . $email);
    }

    private function hostInvitation(int $id): Invitation
    {
        $invitation = $this->host()->find(Invitation::class, $id);
        self::assertInstanceOf(Invitation::class, $invitation);

        return $invitation;
    }

    private function hostChallenge(string $id): QrLoginChallenge
    {
        $challenge = $this->host()->find(QrLoginChallenge::class, $id);
        self::assertInstanceOf(QrLoginChallenge::class, $challenge);

        return $challenge;
    }

    private function column(string $column, int $id): mixed
    {
        return $this->connection()->fetchOne('SELECT ' . $column . ' FROM auth_kit_invitation WHERE id = ' . $id);
    }

    private function challengeStatus(string $id): mixed
    {
        return $this->connection()->fetchOne("SELECT status FROM auth_kit_qr_login_challenge WHERE id = '" . $id . "'");
    }

    private function invitationRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM auth_kit_invitation');
    }

    private function connection(): Connection
    {
        return $this->host()->getConnection();
    }

    private function host(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function manager(): InvitationManager
    {
        $service = self::getContainer()->get(InvitationManager::class);
        assert($service instanceof InvitationManager);

        return $service;
    }

    private function acceptor(): InvitationAcceptor
    {
        $service = self::getContainer()->get(InvitationAcceptor::class);
        assert($service instanceof InvitationAcceptor);

        return $service;
    }

    private function qr(): QrLoginChallengeManager
    {
        $service = self::getContainer()->get(QrLoginChallengeManager::class);
        assert($service instanceof QrLoginChallengeManager);

        return $service;
    }

    private function invitations(): InvitationRepository
    {
        $service = self::getContainer()->get(InvitationRepository::class);
        assert($service instanceof InvitationRepository);

        return $service;
    }

    private function challenges(): QrLoginChallengeRepository
    {
        $service = self::getContainer()->get(QrLoginChallengeRepository::class);
        assert($service instanceof QrLoginChallengeRepository);

        return $service;
    }
}
