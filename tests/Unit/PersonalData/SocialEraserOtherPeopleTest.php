<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;

/**
 * GY-M1, the audit's PoC on a real database: links `user_id=7,8`, where 7 is alice and 8 is an attacker named "7".
 */
final class SocialEraserOtherPeopleTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE social (id INTEGER PRIMARY KEY, user_class TEXT, user_id TEXT, user_identifier TEXT)');
        $this->row('7', 'alice');
        $this->row('8', '7');
    }

    public function testAUsernameEqualToAnotherUsersIdDoesNotReachThatUsersLink(): void
    {
        $this->erase(8, '7');

        self::assertSame(['7'], $this->connection->fetchFirstColumn('SELECT user_id FROM social'), "Erasing the attacker leaves alice's link.");
    }

    public function testARowStoredTheOldWayStillGoes(): void
    {
        $this->row('alice', 'alice');

        $this->erase(7, 'alice');

        self::assertSame(['8'], $this->connection->fetchFirstColumn('SELECT user_id FROM social'), 'Both the id row and the identifier row go.');
    }

    public function testAnotherUsersRowStoredTheOldWayStays(): void
    {
        $this->row('bob', 'bob');

        $this->erase(7, 'alice');

        self::assertSame(['8', 'bob'], $this->connection->fetchFirstColumn('SELECT user_id FROM social ORDER BY id'));
    }

    private function row(string $userId, string $identifier): void
    {
        $this->connection->insert('social', ['user_class' => TestUser::class, 'user_id' => $userId, 'user_identifier' => $identifier]);
    }

    private function erase(int $id, string $identifier): void
    {
        $socialMetadata = new ClassMetadata(SocialLoginAccount::class);
        $socialMetadata->setPrimaryTable(['name' => 'social']);
        $socialManager = $this->createStub(EntityManagerInterface::class);
        $socialManager->method('getConnection')->willReturn($this->connection);
        $socialManager->method('getClassMetadata')->willReturn($socialMetadata);
        $socialDoctrine = $this->createStub(ManagerRegistry::class);
        $socialDoctrine->method('getManagerForClass')->willReturn($socialManager);

        $userMetadata = $this->createStub(ClassMetadata::class);
        $userMetadata->method('getIdentifierValues')->willReturn(['id' => $id]);
        $userMetadata->rootEntityName = TestUser::class;
        $factory                      = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $userManager = $this->createStub(EntityManagerInterface::class);
        $userManager->method('getMetadataFactory')->willReturn($factory);
        $userManager->method('getClassMetadata')->willReturn($userMetadata);
        $userDoctrine = $this->createStub(ManagerRegistry::class);
        $userDoctrine->method('getManagerForClass')->willReturn($userManager);

        (new SocialAccountPersonalDataEraser($socialDoctrine, ProfileRegistryFactory::single(TestUser::class), new MfaOwnerResolver($userDoctrine)))
            ->erase(new ErasureRequest(new TestUser($identifier), null, []));
    }
}
