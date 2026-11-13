<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use DateTimeImmutable;
use Deprecated;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Support\SingleManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function bin2hex;
use function count;
use function hash;
use function random_bytes;
use function sprintf;
use function str_repeat;

/**
 * REQ-SEC-006 (1.46.1): MFA credentials, social links, switcher entries and invitations are matched byte for byte.
 *
 * Every test runs twice: on tables whose identity columns still fold (created before 1.46.1: `NOCASE` on SQLite,
 * `utf8mb4_unicode_ci` on MySQL 8.4 / MariaDB 11.4 in the CI job `real-databases`) and on the schema of the bundle's
 * collation listener. The {@see TestUser} is not a Doctrine entity, so its MFA owner id is its e-mail: the condition of
 * the finding (string owner ids).
 */
final class LookAlikeIdentityTest extends TestCase
{
    private const VICTIM = 'jose@x.test';

    /** @return iterable<string, array{bool}> */
    public static function schemas(): iterable
    {
        yield 'folding collation (before 1.46.1)' => [true];
        yield 'collation listener' => [false];
    }

    /**
     * The look-alikes of {@see self::VICTIM} an attacker can hold as a distinct account and that MfaOwner accepts.
     *
     * @return iterable<string, array{bool, string}>
     */
    public static function lookAlikeOwners(): iterable
    {
        foreach (self::schemas() as $name => [$folding]) {
            yield $name . ', case' => [$folding, 'Jose@x.test'];
            yield $name . ', accent' => [$folding, 'josé@x.test'];
            yield $name . ', decomposed accent (NFD)' => [$folding, "jose\u{0301}@x.test"];
        }
    }

    #[DataProvider('lookAlikeOwners')]
    public function testEnrolmentAsALookAlikeNeverTouchesTheVictimsCredential(bool $folding, string $attacker): void
    {
        $em      = LookAlikeDatabase::entityManager($folding);
        $manager = $this->manager($em);
        $victim  = new TestUser(self::VICTIM);
        $codes   = $manager->enable($victim, $secret = $manager->newSecret(), $this->code($manager, $secret));
        self::assertIsArray($codes);
        $before = $this->victimRow($em);

        self::assertNull($manager->credential(new TestUser($attacker)), 'A look-alike never resolves the victim\'s credential.');
        self::assertFalse($manager->isEnabled(new TestUser($attacker)));

        $attackerSecret = $manager->newSecret();
        try {
            $attackerCodes = $manager->enable(new TestUser($attacker), $attackerSecret, $this->code($manager, $attackerSecret));
            self::assertIsArray($attackerCodes);
            self::assertSame($attacker, $this->manager($em)->credential(new TestUser($attacker))?->getUserId());
        } catch (RuntimeException $e) {
            // A folding unique key (table not altered yet): the insert fails closed, never falls back to the victim's row.
            self::assertTrue($folding, 'Only a folding unique key may refuse the enrolment.');
            self::assertStringContainsString('uniq_auth_kit_mfa_owner', $e->getMessage());
            $em = LookAlikeDatabase::reconnect($em);
        }

        self::assertSame($before, $this->victimRow($em), 'The victim\'s secret, enrolment and recovery codes are unchanged.');
        $manager = $this->manager($em);
        $manager->disable(new TestUser($attacker));
        self::assertSame($before, $this->victimRow($em), 'Disabling the look-alike never removes the victim\'s credential.');
        self::assertTrue($manager->isEnabled($victim));
        self::assertSame([], $manager->exportUser(new TestUser($attacker))['trusted_devices'] ?? []);
    }

    /**
     * Ids MfaOwner refuses: they could never be told apart from the victim's (REQ-SEC-006 item 1).
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'trailing space' => [self::VICTIM . ' '];
        yield 'leading space' => [' ' . self::VICTIM];
        yield 'new line' => [self::VICTIM . "\n"];
        yield 'NBSP' => [self::VICTIM . "\u{00A0}"];
        yield 'ZWSP' => ["jose\u{200B}@x.test"];
        yield 'BOM' => ["\u{FEFF}" . self::VICTIM];
        yield 'bidi mark' => ["jose\u{202E}@x.test"];
        yield 'line separator' => ["jose\u{2028}@x.test"];
        yield 'NUL' => ["jose\0@x.test"];
        yield 'invalid UTF-8' => ["jos\xC3@x.test"];
        yield 'over-long' => [str_repeat('j', MfaOwner::MAX_LENGTH + 1)];
    }

    #[DataProvider('refusedIds')]
    public function testUnusableIdsAreNeverOwners(string $id): void
    {
        self::assertFalse(MfaOwner::usable($id));
        self::assertNull((new MfaOwnerResolver())->resolve(new TestUser($id)));
        $this->expectException(InvalidArgumentException::class);
        new MfaOwner(TestUser::class, $id);
    }

    public function testUsableIdsStayOwners(): void
    {
        foreach (['jose@x.test', 'josé@x.test', 'Bob', '42', 'a b', '0', str_repeat('j', MfaOwner::MAX_LENGTH)] as $id) {
            self::assertSame($id, (new MfaOwnerResolver())->resolve(new TestUser($id))?->id, $id);
        }
        self::assertFalse(MfaOwner::usable(''));
    }

    #[DataProvider('schemas')]
    public function testAUserWithARefusedIdWhoEnrolledBeforeStaysClosed(bool $folding): void
    {
        $em = LookAlikeDatabase::entityManager($folding);
        // A credential enrolled under a padded id before 1.46.1 (the bundle no longer reaches it).
        $legacy = new MfaCredential('default', TestUser::class, 'bob ', new DateTimeImmutable('2026-11-01 10:00:00'));
        $legacy->enable('PLACEHOLDERSECRET', 1, new DateTimeImmutable('2026-11-01 10:00:00'));
        $em->persist($legacy);
        $em->flush();
        $manager = $this->manager($em);
        $store   = new MfaStore(new SingleManagerRegistry($em));

        self::assertFalse($manager->isEnabled(new TestUser('bob ')));
        self::assertTrue($store->hasEnabledCredentialFor('default', TestUser::class, 'bob '));
        self::assertFalse($store->hasEnabledCredentialFor('default', TestUser::class, 'bob'));
        self::assertTrue($manager->mustEnrolNow(new TestUser('bob ')), 'The first factor alone never signs in a user who enrolled.');
        self::assertFalse($manager->mustEnrolNow(new TestUser("bob\u{200B}")), 'An optional user without a credential signs in as before (no MFA possible).');
        self::assertFalse($manager->mustEnrolNow(new TestUser('bob')), 'The look-alike "bob" never inherits "bob "\'s credential.');
        self::assertTrue($this->manager($em, ['required_roles' => ['ROLE_USER']])->mustEnrolNow(new TestUser("bob\u{200B}")), 'A required user with a refused id must not skip MFA.');

        // Personal-data erasure still deletes the legacy row, byte for byte, and nothing of the look-alike "bob".
        $em->getConnection()->insert(MfaCredential::TABLE, ['profile' => 'other', 'user_type' => TestUser::class, 'user_id' => 'bob', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        $em->getConnection()->insert(MfaCredential::TABLE, ['profile' => 'other', 'user_type' => TestUser::class, 'user_id' => "ann\u{00A0}", 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        self::assertTrue($manager->hasStoredCredentials(new TestUser('bob ')));
        self::assertTrue($manager->eraseUser(new TestUser('bob ')));
        self::assertFalse($store->hasEnabledCredentialFor('default', TestUser::class, 'bob '));
        self::assertTrue($manager->eraseUser(new TestUser('ann@x.test'), "ann\u{00A0}"), 'A refused original identifier is erased too.');
        self::assertSame(['bob'], $em->getConnection()->fetchFirstColumn('SELECT user_id FROM ' . MfaCredential::TABLE));
        self::assertSame([], (new MfaOwnerResolver())->erasureOwners(new TestUser(str_repeat('b', MfaOwner::MAX_LENGTH + 1)), null), 'An over-long id has no owner at all.');
    }

    public function testALegacyOwnerIsStillNonEmptyAndBounded(): void
    {
        self::assertSame('bob ', MfaOwner::legacy(TestUser::class, 'bob ')->id);
        $this->expectException(InvalidArgumentException::class);
        MfaOwner::legacy(TestUser::class, '');
    }

    public function testRefusedReferenceOnlyReportsUnusableCharacters(): void
    {
        $resolver = new MfaOwnerResolver();
        self::assertNull($resolver->refusedReference(new TestUser('bob')));
        $empty = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            #[Deprecated]
            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return ''; // @phpstan-ignore return.type (a host user breaking the contract)
            }
        };
        self::assertNull($resolver->refusedReference($empty));
        self::assertNull($resolver->resolve($empty));
        self::assertNull($resolver->refusedReference(new TestUser(str_repeat('b', MfaOwner::MAX_LENGTH + 1) . ' ')));
        self::assertSame([TestUser::class, 'bob '], $resolver->refusedReference(new TestUser('bob ')));
        self::assertNull($resolver->refusedReference(new stdClass()));
    }

    #[DataProvider('schemas')]
    public function testCredentialsOfKeepsOnlyTheExactOwner(bool $folding): void
    {
        $em = LookAlikeDatabase::entityManager($folding);
        $em->getConnection()->insert(MfaCredential::TABLE, ['profile' => 'other', 'user_type' => TestUser::class, 'user_id' => 'Jose@x.test', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        $em->getConnection()->insert(MfaCredential::TABLE, ['profile' => 'default', 'user_type' => TestUser::class, 'user_id' => self::VICTIM, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        $em->getConnection()->insert(MfaCredential::TABLE, ['profile' => 'third', 'user_type' => 'Nowo\AuthKitBundle\Tests\Stub\testuser', 'user_id' => self::VICTIM, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        $store = new MfaStore(new SingleManagerRegistry($em));

        self::assertSame(['default'], array_map(static fn (MfaCredential $c): string => $c->getProfile(), $store->credentialsOf(new MfaOwner(TestUser::class, self::VICTIM))));
        self::assertNull($store->credential('other', new MfaOwner(TestUser::class, self::VICTIM)));
        self::assertNull($store->credential('third', new MfaOwner(TestUser::class, self::VICTIM)), 'The owner type matches byte for byte too.');
    }

    #[DataProvider('schemas')]
    public function testLookAlikeSocialSubjectsNeverHideTheExactOne(bool $folding): void
    {
        $em = LookAlikeDatabase::entityManager($folding);
        // More look-alike links than one batch, all older than the exact one (each for its own class: a folding unique
        // key refuses two look-alikes of one class).
        $variants = ['ABCD', 'Abcd', 'aBcd', 'abCd', 'abcD', 'ABcd', 'AbCd', 'AbcD', 'aBCd', 'aBcD', 'abCD', 'ABCd'];
        foreach ($variants as $i => $subject) {
            $this->link($em, $subject, 'App\Entity\User' . $i);
        }
        $this->link($em, 'abcd', 'App\Entity\Owner');
        $repository = new SocialLoginAccountRepository(new SingleManagerRegistry($em));

        self::assertSame('App\Entity\Owner', $repository->findOneByProviderSubject('google', 'abcd')?->getUserClass());
        self::assertSame('App\Entity\Owner', $repository->findOneByProviderSubject('google', 'abcd', 'App\Entity\Owner')?->getUserClass());
        self::assertNull($repository->findOneByProviderSubject('google', 'abcd', 'App\Entity\User0'));
        self::assertNull($repository->findOneByProviderSubject('google', 'ABCDE'));
        self::assertGreaterThan(SocialLoginAccountRepository::CANDIDATE_BATCH, count($variants));
    }

    #[DataProvider('schemas')]
    public function testTheVaultDeletesOnlyTheByteIdenticalIdentifier(bool $folding): void
    {
        $em    = LookAlikeDatabase::entityManager($folding);
        $store = new AccountVaultStore(new SingleManagerRegistry($em));
        $now   = new DateTimeImmutable('2026-11-01 10:00:00');
        foreach (['bob', 'Bob', 'BOB', 'bob'] as $identifier) {
            $store->insert(bin2hex(random_bytes(16)), 'default', $identifier, hash('sha256', random_bytes(8)), null, $now, $now->modify('+1 day'));
        }

        self::assertSame(2, $store->deleteForUser('default', 'bob'));
        self::assertSame(0, $store->deleteForUser('default', 'bob'));
        self::assertSame(1, $store->deleteForUser('default', 'Bob'));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_account_vault'));
    }

    #[DataProvider('schemas')]
    public function testInvitationsOfALookAlikeAddressOrInviterAreNeverUsed(bool $folding): void
    {
        $em          = LookAlikeDatabase::entityManager($folding);
        $invitations = new InvitationRepository($em);
        $now         = new DateTimeImmutable();
        $own         = new Invitation('default', 'jose@x.test', 'admin', 7, $now);
        $own->setPayload(['team' => 'a']);
        $own->issue(hash('sha256', 'own'), 7, $now);
        $invitations->save($own);
        // Newer look-alike rows than one batch (a table that predates the e-mail normalisation, or written by the host).
        for ($i = 0; $i < InvitationRepository::CANDIDATE_BATCH + 5; ++$i) {
            $other = new Invitation('default', 'x' . $i . '@x.test', $i % 2 === 0 ? 'Admin' : 'ADMIN', 7, $now);
            $other->setPayload(['team' => 'a']);
            $other->issue(hash('sha256', 'other' . $i), 7, $now);
            (new ReflectionProperty(Invitation::class, 'email'))->setValue($other, $i % 2 === 0 ? 'JOSE@x.test' : 'Jose@X.test');
            $invitations->save($other);
        }

        self::assertSame($own->getId(), $invitations->findOpenByEmail('default', 'jose@x.test')?->getId());
        self::assertSame($own->getId(), $invitations->findOpenByEmailAndPayloadKey('default', 'jose@x.test', 'team', 'a', $now)?->getId());
        self::assertNull($invitations->findOpenByEmail('default', 'josé@x.test'));
        self::assertNull($invitations->findOpenByEmail('other', 'jose@x.test'));
        self::assertSame([$own->getId()], array_map(static fn (Invitation $i): ?int => $i->getId(), $invitations->findOpenByInviter('admin', 1)));
        self::assertSame([$own->getId()], array_map(static fn (Invitation $i): ?int => $i->getId(), $invitations->findOpenByInviter('admin', 10, 'default')));
        self::assertSame([], $invitations->findOpenByInviter('admin', 10, 'other'));
        self::assertCount(2, $invitations->findOpenByInviter('ADMIN', 2));
    }

    /**
     * The probe of the audit on the real server: with the listener's NO PAD binary collation, `Bob`, `bób`, `bob `,
     * `bob\n`, NBSP and ZWSP variants are distinct keys of the unique index and never equal `bob` in SQL either.
     */
    public function testTheListenerMakesTheIdentityColumnsExactOnTheServer(): void
    {
        $em         = LookAlikeDatabase::entityManager(false);
        $connection = $em->getConnection();
        $set        = ['bob', 'Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}"];
        foreach ($set as $id) {
            $connection->insert(MfaCredential::TABLE, ['profile' => 'default', 'user_type' => TestUser::class, 'user_id' => $id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
        }

        self::assertSame(count($set), (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . MfaCredential::TABLE), 'The unique key no longer folds.');
        self::assertSame(['bob'], $connection->fetchFirstColumn('SELECT user_id FROM ' . MfaCredential::TABLE . " WHERE user_id = 'bob'"));
        if (!LookAlikeDatabase::isMysql($em)) {
            return;
        }
        $expected = $connection->getDatabasePlatform() instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin'; // @codeCoverageIgnore
        $columns  = ['auth_kit_mfa' => ['user_type', 'user_id'], 'auth_kit_social_account' => ['provider_user_id', 'user_id', 'user_identifier'], 'auth_kit_account_vault' => ['user_identifier'], 'auth_kit_invitation' => ['email', 'invited_by']]; // @codeCoverageIgnore
        foreach ($columns as $table => $names) { // @codeCoverageIgnore
            foreach ($names as $name) { // @codeCoverageIgnore
                self::assertSame($expected, $connection->fetchOne('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $name]), $table . '.' . $name); // @codeCoverageIgnore
            }
        }
    }

    /**
     * @param array<string, mixed> $mfa
     */
    private function manager(EntityManagerInterface $em, array $mfa = []): MfaManager
    {
        return new MfaManager(
            ProfileRegistryFactory::single(TestUser::class, ['mfa' => ['enabled' => true] + $mfa]),
            new MfaStore(new SingleManagerRegistry($em)),
            new MfaOwnerResolver(),
            new MockClock('2026-11-13 10:00:00'),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
    }

    private function code(MfaManager $manager, string $secret): string
    {
        $totp = $manager->totp(ProfileRegistryFactory::single(TestUser::class, ['mfa' => ['enabled' => true]])->getByName('default'));

        return $totp->code($secret, $totp->step($manager->now()->getTimestamp()));
    }

    /**
     * The victim's credential and recovery codes as stored.
     *
     * @return array<string, mixed>
     */
    private function victimRow(EntityManagerInterface $em): array
    {
        $connection = $em->getConnection();
        $rows       = $connection->fetchAllAssociative('SELECT id, user_id, totp_secret, enabled_at FROM ' . MfaCredential::TABLE . ' ORDER BY id');
        $victim     = null;
        foreach ($rows as $row) {
            if ($row['user_id'] === self::VICTIM) {
                $victim = $row;
            }
        }
        self::assertNotNull($victim, 'The victim\'s credential still exists.');

        return $victim + ['codes' => $connection->fetchFirstColumn(sprintf('SELECT code_hash FROM %s WHERE mfa_id = ? ORDER BY code_hash', MfaRecoveryCode::TABLE), [$victim['id']])];
    }

    private function link(EntityManagerInterface $em, string $subject, string $class): void
    {
        $em->persist((new SocialLoginAccount())->setProvider('google')->setProviderUserId($subject)->setUserClass($class)->setUserId('1')->setUserIdentifier('u@x.test'));
        $em->flush();
    }
}
