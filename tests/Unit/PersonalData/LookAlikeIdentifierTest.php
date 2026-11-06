<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;

/**
 * GY-I8: on `utf8mb4_0900_ai_ci` the query for `jose@` also returns `josé@`. SQLite cannot collate like that, so the
 * database answer is scripted here: what comes back is what MySQL returned in the audit's `dbal.out`.
 */
final class LookAlikeIdentifierTest extends TestCase
{
    public function testTheVaultDeletesTheIdentifierAndItsCaseVariantButNotALookAlike(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'user_identifier' => 'jose@example.com'],
            ['id' => 2, 'user_identifier' => 'JOSE@example.com'],
            ['id' => 3, 'user_identifier' => 'josé@example.com'],
        ]);
        $connection->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('DELETE'), ['ids' => [1, 2]], self::anything())
            ->willReturn(2);

        self::assertSame(2, (new AccountVaultStore($this->doctrine($connection, AccountVaultEntry::class)))->deleteForUser('default', 'jose@example.com'));
    }

    public function testTheVaultRunsNoDeleteForOnlyALookAlike(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 3, 'user_identifier' => 'josé@example.com']]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(0, (new AccountVaultStore($this->doctrine($connection, AccountVaultEntry::class)))->deleteForUser('default', 'jose@example.com'));
    }

    public function testMfaCredentialsDropALookAlike(): void
    {
        $now        = new DateTimeImmutable();
        $own        = new MfaCredential('default', 'App\User', 'jose@example.com', $now);
        $lookAlike  = new MfaCredential('default', 'App\User', 'josé@example.com', $now);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([$own, $lookAlike]);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($manager);

        self::assertSame([$own], (new MfaStore($doctrine))->credentialsOf(new MfaOwner('App\User', 'jose@example.com')));
    }

    public function testTheSocialEraserDropsALookAlikeNaturalKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $schema     = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'user_id' => 'jose@example.com'],
            ['id' => 2, 'user_id' => 'josé@example.com'],
        ]);
        $connection->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('DELETE'), ['rows' => [1]], self::anything());

        $eraser = new SocialAccountPersonalDataEraser(
            $this->doctrine($connection, SocialLoginAccount::class),
            ProfileRegistryFactory::single(TestUser::class),
            new MfaOwnerResolver(),
        );
        $eraser->erase(new ErasureRequest(new TestUser('jose@example.com'), null, []));
    }

    public function testTheSocialEraserRunsNoDeleteForOnlyALookAlike(): void
    {
        $connection = $this->createMock(Connection::class);
        $schema     = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 2, 'user_id' => 'josé@example.com']]);
        $connection->expects(self::never())->method('executeStatement');

        $eraser = new SocialAccountPersonalDataEraser(
            $this->doctrine($connection, SocialLoginAccount::class),
            ProfileRegistryFactory::single(TestUser::class),
            new MfaOwnerResolver(),
        );
        $eraser->erase(new ErasureRequest(new TestUser('jose@example.com'), null, []));
    }

    /** GY-I9: a surrogate-key user also loses a row the linker stored under its identifier before 1.37. */
    public function testTheSocialEraserAsksForTheIdAndTheIdentifier(): void
    {
        $connection = $this->createMock(Connection::class);
        $schema     = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(self::anything(), self::callback(static fn (array $parameters): bool => $parameters['ids'] === ['7', 'someone@example.com']), self::anything())
            ->willReturn([]);

        $userMetadata = $this->createStub(ClassMetadata::class);
        $userMetadata->method('getIdentifierValues')->willReturn(['id' => 7]);
        $userMetadata->rootEntityName = TestUser::class;
        $factory                      = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $userManager = $this->createStub(EntityManagerInterface::class);
        $userManager->method('getMetadataFactory')->willReturn($factory);
        $userManager->method('getClassMetadata')->willReturn($userMetadata);
        $userDoctrine = $this->createStub(ManagerRegistry::class);
        $userDoctrine->method('getManagerForClass')->willReturn($userManager);

        $eraser = new SocialAccountPersonalDataEraser(
            $this->doctrine($connection, SocialLoginAccount::class),
            ProfileRegistryFactory::single(TestUser::class),
            new MfaOwnerResolver($userDoctrine),
        );
        $eraser->erase(new ErasureRequest(new TestUser('someone@example.com'), null, [], null));
    }

    /**
     * @param class-string $entity
     */
    private function doctrine(Connection $connection, string $entity): ManagerRegistry
    {
        $metadata = new ClassMetadata($entity);
        $metadata->setPrimaryTable(['name' => 'some_table']);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($manager);

        return $doctrine;
    }
}
