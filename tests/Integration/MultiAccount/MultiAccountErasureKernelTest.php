<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataEraser;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\MultiAccount\PersonalData\AccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\Model\SessionUser;
use Nowo\UserKitBundle\Sessions\PersonalData\SessionsEraser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_search;
use function json_encode;
use function property_exists;

/**
 * The whole UserKit eraser chain with a host identity eraser that anonymises the e-mail (the vault key) at priority
 * 90: the person leaves every browser's account switcher, and their second factor is deleted; the export has an `mfa`
 * section without secrets.
 */
final class MultiAccountErasureKernelTest extends MultiAccountKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiAccountErasureTestKernel::class;
    }

    public function testTheFullEraserChainRemovesTheVaultEntriesAndTheSecondFactor(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        $this->enableMfa('member@example.com');
        self::assertGreaterThan(0, $this->vaultRows('member@example.com'));
        self::assertNotNull($this->manager()->credential($this->user('member@example.com')));
        $credentials = $this->rows(MfaCredential::TABLE);
        $codes       = $this->rows(MfaRecoveryCode::TABLE);

        $member = $this->user('member@example.com');
        $ran    = $this->eraser()->erase($member);

        // The AuthKit erasers ran first, the identity eraser next, UserKit's sessions eraser last.
        $mfa      = array_search(MfaPersonalDataEraser::KEY, $ran, true);
        $switcher = array_search(AccountSwitcherPersonalDataEraser::KEY, $ran, true);
        $identity = array_search('identity', $ran, true);
        $sessions = array_search('sessions', $ran, true);
        self::assertIsInt($mfa);
        self::assertIsInt($switcher);
        self::assertIsInt($identity);
        self::assertIsInt($sessions);
        self::assertLessThan($identity, $mfa);
        self::assertLessThan($identity, $switcher);
        self::assertLessThan($sessions, $identity);
        self::assertStringStartsWith('erased-', $member->getUserIdentifier());

        self::assertSame(0, $this->vaultRows('member@example.com'), 'The erased person left every switcher.');
        self::assertSame(0, $this->rows(AccountVaultEntry::TABLE, "user_identifier LIKE 'erased-%'"));
        self::assertGreaterThan(0, $this->vaultRows('admin@example.com'));
        self::assertNull($this->manager()->credential($this->user('member@example.com')), 'The second factor is gone.');
        self::assertSame($credentials - 1, $this->rows(MfaCredential::TABLE));
        self::assertSame($codes - 10, $this->rows(MfaRecoveryCode::TABLE));
        self::assertSame(0, $this->rows(MfaTrustedDevice::TABLE));
        self::assertSame(['admin@example.com|active'], $this->accounts());
    }

    public function testTheSessionsEraserAloneForgetsTheOriginalIdentifier(): void
    {
        // Without AuthKit's eraser (an older host order), the revocation of UserKit's sessions eraser carries the
        // identifier captured before the identity eraser ran, and the subscriber forgets it (UserKit >= 1.12).
        if (!property_exists(ErasureRequest::class, 'originalIdentifier')) {
            self::markTestSkipped('UserKit < 1.12 does not carry the original identifier.');
        }
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertGreaterThan(0, $this->vaultRows('member@example.com'));
        $container = self::getContainer();
        $identity  = $container->get(RenameIdentityEraser::class);
        $sessions  = $container->get(SessionsEraser::class);
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(PersonalDataEraserInterface::class, $identity);
        self::assertInstanceOf(PersonalDataEraserInterface::class, $sessions);
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        self::assertSame(['identity', 'sessions'], (new PersonalDataEraser([$identity, $sessions], [], $doctrine))->erase($this->user('member@example.com')));

        self::assertSame(0, $this->vaultRows('member@example.com'));
    }

    public function testAnIdentityEraserBeforeAuthKitDoesNotHideTheSecondFactor(): void
    {
        // FH-L1: the test user's Doctrine id is its e-mail (the identifier). A host identity eraser above priority 200
        // anonymises it before `auth_kit_mfa` runs; the rows are still found by the identifier captured when the
        // erasure started (UserKit >= 1.12). Other users' rows are kept, and a second erasure is a no-op.
        if (!property_exists(ErasureRequest::class, 'originalIdentifier')) {
            self::markTestSkipped('UserKit < 1.12 does not carry the original identifier.');
        }
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->enableMfa('member@example.com');
        $this->enableMfa('admin@example.com');
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $chain = [];
        foreach ([RenameIdentityEraser::class, MfaPersonalDataEraser::class, AccountSwitcherPersonalDataEraser::class, SessionsEraser::class] as $id) {
            $eraser = $container->get($id);
            self::assertInstanceOf(PersonalDataEraserInterface::class, $eraser);
            $chain[] = $eraser;
        }
        $eraser      = new PersonalDataEraser($chain, [], $doctrine);
        $credentials = $this->rows(MfaCredential::TABLE);
        $codes       = $this->rows(MfaRecoveryCode::TABLE);
        $member      = $this->user('member@example.com');

        self::assertSame(['identity', MfaPersonalDataEraser::KEY, AccountSwitcherPersonalDataEraser::KEY, 'sessions'], $eraser->erase($member));

        self::assertSame(0, $this->rows(MfaCredential::TABLE, "user_id = 'member@example.com'"), 'The TOTP secret is gone.');
        self::assertSame($credentials - 1, $this->rows(MfaCredential::TABLE));
        self::assertSame($codes - 10, $this->rows(MfaRecoveryCode::TABLE));
        self::assertSame(0, $this->vaultRows('member@example.com'));
        self::assertNotNull($this->manager()->credential($this->user('admin@example.com')), 'Other users keep their MFA.');
        self::assertGreaterThan(0, $this->vaultRows('admin@example.com'));

        $eraser->erase($member);
        self::assertSame($credentials - 1, $this->rows(MfaCredential::TABLE));
    }

    public function testEraseUserFindsIdentifierKeyedRowsUnderTheOriginalIdentifierInEveryProfile(): void
    {
        // FH-L1 / FH-L2 without the UserKit chain (works with UserKit 1.11): rows of an identifier-keyed owner are
        // found by the original identifier after the e-mail changed, in the current profile and in any other one.
        $this->signIn('member@example.com', 'member-password-123');
        $this->enableMfa('member@example.com');
        $this->enableMfa('admin@example.com');
        // A credential left in a profile whose MFA was turned off (or that the class no longer resolves to).
        $this->em()->persist(new MfaCredential('legacy', KernelUser::class, 'member@example.com', new DateTimeImmutable()));
        $this->em()->flush();
        self::assertSame(2, $this->rows(MfaCredential::TABLE, "user_id = 'member@example.com'"));
        $credentials = $this->rows(MfaCredential::TABLE);

        $member = $this->user('member@example.com');
        $this->em()->detach($member);
        $member->setEmail('erased-x@invalid.local');
        self::assertFalse($this->manager()->hasStoredCredentials($member), 'The anonymised identifier alone finds nothing.');
        self::assertFalse($this->manager()->eraseUser($member));
        self::assertTrue($this->manager()->hasStoredCredentials($member, 'member@example.com'));

        self::assertTrue($this->manager()->eraseUser($member, 'member@example.com'));
        $this->em()->flush();

        self::assertSame(0, $this->rows(MfaCredential::TABLE, "user_id = 'member@example.com'"));
        self::assertSame($credentials - 2, $this->rows(MfaCredential::TABLE));
        self::assertNotNull($this->manager()->credential($this->user('admin@example.com')));
        self::assertFalse($this->manager()->eraseUser($member, 'member@example.com'));
    }

    public function testTheSubscriberForgetsTheIdentifierOfTheRevokeContext(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $member   = $this->user('member@example.com');
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);
        $owner = $sessions->owner($member);
        self::assertInstanceOf(SessionUser::class, $owner);
        $this->em()->detach($member);
        $member->setEmail('erased-x@invalid.local');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        // Without the context the anonymised identifier matches nothing.
        $before = $this->vaultRows('member@example.com');
        $dispatcher->dispatch(new UserSessionsRevokedEvent($owner, $member, null, RevocationReason::AccountChanged, []));
        self::assertGreaterThan(0, $before);
        self::assertSame($before, $this->vaultRows('member@example.com'));
        $dispatcher->dispatch(new UserSessionsRevokedEvent($owner, $member, null, RevocationReason::AccountChanged, [], context: [MultiAccountSubscriber::CONTEXT_ORIGINAL_IDENTIFIER => 'member@example.com']));
        self::assertSame(0, $this->vaultRows('member@example.com'));
        self::assertGreaterThan(0, $this->vaultRows('admin@example.com'));
    }

    public function testErasersIgnoreUsersTheyDoNotHandle(): void
    {
        $switcher = self::getContainer()->get(AccountSwitcherPersonalDataEraser::class);
        self::assertInstanceOf(AccountSwitcherPersonalDataEraser::class, $switcher);
        self::assertFalse($switcher->supports(new stdClass()));
        $switcher->erase(new ErasureRequest(new stdClass()));
        $mfa = self::getContainer()->get(MfaPersonalDataEraser::class);
        self::assertInstanceOf(MfaPersonalDataEraser::class, $mfa);
        self::assertFalse($mfa->supports(new stdClass()));
        $mfa->erase(new ErasureRequest(new stdClass()));
        // A user without a second factor: nothing to delete.
        self::assertFalse($this->manager()->eraseUser($this->user('admin@example.com')));
        self::assertNull($this->manager()->exportUser($this->user('admin@example.com')));
    }

    public function testTheExportHasAnMfaSectionWithoutSecrets(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enableMfa('member@example.com');

        $exporter = self::getContainer()->get('test.personal_data.exporter');
        self::assertInstanceOf(PersonalDataExporter::class, $exporter);
        $document = $exporter->export($this->user('member@example.com'));
        $mfa      = $document['sections']['mfa'] ?? null;
        self::assertIsArray($mfa);
        self::assertSame('totp', $mfa['method']);
        self::assertTrue($mfa['enabled']);
        self::assertIsString($mfa['enabled_at']);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $mfa['enabled_at']));
        self::assertSame(10, $mfa['unused_recovery_codes']);
        self::assertSame([], $mfa['trusted_devices']);
        self::assertArrayNotHasKey('secret', $mfa);
        self::assertStringNotContainsString($this->secretOf('member@example.com'), (string) json_encode($document));

        self::assertNull($exporter->export($this->user('admin@example.com'))['sections']['mfa'] ?? null);
    }

    private function eraser(): PersonalDataEraser
    {
        $eraser = self::getContainer()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        return $eraser;
    }

    private function vaultRows(string $identifier): int
    {
        return $this->rows(AccountVaultEntry::TABLE, 'user_identifier = ' . $this->em()->getConnection()->quote($identifier));
    }

    private function rows(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
    }
}
