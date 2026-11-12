<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use LogicException;
use Nowo\AuthKitBundle\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCredentials;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\PasswordReset\TablePasswordResetTokenManager;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\PasswordResetRequestRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Stub\PlainResetUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\UserIds;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_replace_recursive;
use function hash;

/**
 * Edge cases of the table storage (1.46.0) that the equivalence scenario does not reach: a row of another user class,
 * a row whose user is gone or is now another user, users without a readable identifier field, users of no profile,
 * the table prefix, the unmapped table, and the invalidation listener on users it must leave alone.
 */
final class TablePasswordResetTokenManagerTest extends TestCase
{
    public function testALinkRowOfAnotherUserClassIsSkipped(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $this->row($h, 'App\\Other\\User', '1', 'other@example.com', hash('sha256', 'tok'));
        self::assertNull($h->manager->resolveUserByLinkToken('tok'));

        $this->row($h, ResetUser::class, (string) UserIds::of($ada), 'ada@example.com', hash('sha256', 'tok'));
        $this->row($h, 'App\\Other\\User', '1', 'other@example.com', hash('sha256', 'tok'));
        self::assertSame($ada, $h->manager->resolveUserByLinkToken('tok'));
    }

    public function testARowWhoseUserIsGoneOrChangedResolvesNobody(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $this->row($h, ResetUser::class, '999', 'ghost@example.com', hash('sha256', 'gone'));
        self::assertNull($h->manager->resolveUserByLinkToken('gone'), 'The user of the row no longer exists.');

        $lookup = $this->createStub(UserLookupInterface::class);
        $lookup->method('findById')->willReturn(new TestUser('ada@example.com'));
        $manager = $this->manager($h, $lookup);
        $this->row($h, ResetUser::class, (string) UserIds::of($ada), 'ada@example.com', hash('sha256', 'other-class'));
        self::assertNull($manager->resolveUserByLinkToken('other-class'), 'The lookup answered a user of another class.');

        $other = new ResetUser('eve@example.com');
        $h->em->persist($other);
        $h->em->flush();
        $lookup = $this->createStub(UserLookupInterface::class);
        $lookup->method('findById')->willReturn($other);
        $this->row($h, ResetUser::class, (string) UserIds::of($ada), 'ada@example.com', hash('sha256', 'other-id'));
        self::assertNull($this->manager($h, $lookup)->resolveUserByLinkToken('other-id'), 'The lookup answered another id.');
    }

    public function testAnIdLessRowNeedsTheSameIdentifier(): void
    {
        $h = new ResetStorageHarness('table', 'link', KernelUser::class);
        $h->addUser('ada@example.com');
        $bob = $h->addUser('bob@example.com');

        $lookup = $this->createStub(UserLookupInterface::class);
        $lookup->method('findByIdentifier')->willReturn($bob);
        $this->row($h, KernelUser::class, null, 'ada@example.com', hash('sha256', 'swap'));
        self::assertNull($this->manager($h, $lookup)->resolveUserByLinkToken('swap'));
        self::assertSame('ada@example.com', $this->identifier($h->manager->resolveUserByLinkToken('swap')));
    }

    public function testUsersWithoutTheIdentifierFieldFallBack(): void
    {
        $h        = new ResetStorageHarness('table', 'link', ResetUser::class, [], 'app_');
        $profiles = ProfileRegistryFactory::fromProfiles([
            'default' => array_replace_recursive(ProfileRegistryFactory::defaultProfileConfig(TestUser::class), ['user_identifier_field' => 'username', 'password_reset' => ['storage' => 'table']]),
            'plain'   => array_replace_recursive(ProfileRegistryFactory::defaultProfileConfig(PlainResetUser::class), ['user_identifier_field' => 'username', 'password_reset' => ['storage' => 'table']]),
        ]);
        $manager = $this->manager($h, $this->createStub(UserLookupInterface::class), $profiles);

        self::assertSame('app_auth_kit_password_reset', $h->resetTable());
        $manager->createForUser(new TestUser('sam@example.com'));
        $manager->createForUser(new PlainResetUser());
        self::assertSame(2, $h->count("SELECT COUNT(*) FROM app_auth_kit_password_reset WHERE user_identifier IN ('sam@example.com', '')"));
        $manager->clearForUser(new PlainResetUser());
        self::assertSame(1, $h->count('SELECT COUNT(*) FROM app_auth_kit_password_reset'));
    }

    public function testASpentBudgetClearsTheCredentialBeforeAnyCheck(): void
    {
        $h        = new ResetStorageHarness('table', 'code');
        $ada      = $h->addUser('ada@example.com');
        $limiter  = new AuthKitAttemptLimiter(new ArrayAdapter());
        $manager  = $this->manager($h, new DoctrineUserLookup($h->em), null, $limiter);
        $code     = $manager->createForUser($ada)->plainToken;
        $profile  = $h->profiles->getDefault();
        $accessor = PropertyAccess::createPropertyAccessor();
        foreach ([[$ada, 'ada@example.com'], [null, 'nobody@example.com']] as [$user, $identifier]) {
            $key = PasswordResetCredentials::codeAttemptKey($accessor, $profile, $user, $identifier);
            for ($i = 0; $i < 5; ++$i) {
                $limiter->hit($key, 60);
            }
        }

        self::assertNull($manager->resolveUserByIdentifierAndCode('ada@example.com', $code));
        self::assertSame(0, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()), 'The spent budget cleared the credential.');
        self::assertNull($manager->resolveUserByIdentifierAndCode('nobody@example.com', $code));
    }

    public function testAUserOfNoProfileIsRefused(): void
    {
        $h = new ResetStorageHarness('table');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Auth Kit profile is configured for user class "stdClass".');

        $this->manager($h, $this->createStub(UserLookupInterface::class))->createForUser(new stdClass());
    }

    public function testAProfileNameIsHonoured(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $res = $h->manager->createForUser($ada);

        self::assertSame($ada, $h->manager->resolveUserByLinkToken($res->plainToken, 'default'));
    }

    public function testAFailedSaveRollsBackAndRethrows(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $h->em->getConnection()->executeStatement('DROP TABLE ' . $h->resetTable());

        try {
            $h->manager->createForUser($ada);
            self::fail('The save must fail without its table.');
        } catch (Exception) {
            self::assertFalse($h->em->getConnection()->isTransactionActive());
        }
    }

    public function testTheEntityOnlyMapsTheSchema(): void
    {
        $entity = new PasswordResetRequest('default', ResetUser::class, '1', 'ada@example.com', hash('sha256', 't'), null, new DateTimeImmutable(), new DateTimeImmutable());

        self::assertNull($entity->getId());
    }

    public function testTheTableNeedsTheMapping(): void
    {
        $repository = new PasswordResetRequestRepository(new SqliteManagerRegistry(['default' => ['Nowo\\AuthKitBundle\\Tests\\Integration\\PasswordReset\\Entity' => __DIR__ . '/Entity']]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('password_reset.storage "table" needs them');

        $repository->delete(ResetUser::class, '1', []);
    }

    public function testTheListenerLeavesOtherUsersAndOtherFieldsAlone(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $h->manager->createForUser($ada);

        // Not a profile user: ignored.
        $kernelUser = (new KernelUser())->setEmail('k@example.com')->setPassword('x');
        $h->em->persist($kernelUser);
        $h->em->flush();
        $kernelUser->setPassword('y');
        $h->em->flush();
        // A field that is neither the identifier nor the password: the row stays.
        self::assertInstanceOf(ResetUser::class, $ada);
        $ada->setPasswordResetToken('unrelated');
        $h->em->flush();
        self::assertSame(1, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()));

        // The user deleted: its row goes with it.
        $h->em->remove($ada);
        $h->em->flush();
        self::assertSame(0, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()));
        $h->em->remove($kernelUser);
        $h->em->flush();
    }

    public function testTheListenerIgnoresUserEntityProfiles(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = $h->addUser('ada@example.com');
        $this->row($h, ResetUser::class, (string) UserIds::of($ada), 'ada@example.com', hash('sha256', 'kept'));
        $ada->setPassword('changed');
        $h->em->flush();

        self::assertSame(1, $h->count('SELECT COUNT(*) FROM ' . $h->resetTable()));
    }

    private function manager(ResetStorageHarness $h, UserLookupInterface $lookup, ?ProfileRegistry $profiles = null, ?AuthKitAttemptLimiter $limiter = null): TablePasswordResetTokenManager
    {
        $profiles ??= $h->profiles;

        return new TablePasswordResetTokenManager(
            $h->requests,
            $lookup,
            PropertyAccess::createPropertyAccessor(),
            new PasswordResetUserResolver($h->em, $profiles),
            $profiles,
            $h->clock,
            $limiter ?? new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
    }

    private function identifier(?object $user): ?string
    {
        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    private function row(ResetStorageHarness $h, string $class, ?string $id, string $identifier, string $tokenHash): void
    {
        $h->em->getConnection()->insert($h->resetTable(), [
            'profile'         => 'default',
            'user_class'      => $class,
            'user_id'         => $id,
            'user_identifier' => $identifier,
            'token_hash'      => $tokenHash,
            'code_hash'       => null,
            'expires_at'      => '2026-11-12 11:00:00',
            'created_at'      => '2026-11-12 10:00:00',
        ]);
    }
}
