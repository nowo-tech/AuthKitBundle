<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use PHPUnit\Framework\TestCase;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * SEC-004 H2 at database level: a provider subject linked to a user of one class (profile) never resolves for a
 * profile with another user class, and each class may hold its own link of the same subject.
 */
final class SocialLoginAccountRepositoryTest extends TestCase
{
    private const MEMBER = 'App\\Entity\\Member';
    private const ADMIN  = 'App\\Entity\\Admin';

    private EntityManagerInterface $entityManager;

    private SocialLoginAccountRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo-auth-kit-tests/proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(SocialLoginAccount::class)]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->repository = new SocialLoginAccountRepository($registry);
    }

    public function testASubjectLinkedToAnotherUserClassIsNotFound(): void
    {
        $this->persist(self::MEMBER, '7');

        self::assertNull($this->repository->findOneByProviderSubject('google', 'subject-1', self::ADMIN));
        self::assertSame('7', $this->repository->findOneByProviderSubject('google', 'subject-1', self::MEMBER)?->getUserId());
        // Without a class (pre-1.31 callers) the first link is returned.
        self::assertNotNull($this->repository->findOneByProviderSubject('google', 'subject-1'));
    }

    public function testEachUserClassHoldsItsOwnLinkOfTheSameSubject(): void
    {
        $this->persist(self::MEMBER, '7');
        $this->persist(self::ADMIN, '7');

        self::assertSame(self::ADMIN, $this->repository->findOneByProviderSubject('google', 'subject-1', self::ADMIN)?->getUserClass());
        self::assertSame(self::MEMBER, $this->repository->findOneByProviderSubject('google', 'subject-1', self::MEMBER)?->getUserClass());
    }

    public function testTheSameSubjectCannotBeLinkedTwiceForOneUserClass(): void
    {
        $this->persist(self::MEMBER, '7');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->persist(self::MEMBER, '8');
    }

    public function testSubjectAndProviderMustMatchExactly(): void
    {
        $this->persist(self::MEMBER, '7');

        self::assertNull($this->repository->findOneByProviderSubject('google', 'SUBJECT-1', self::MEMBER));
        self::assertNull($this->repository->findOneByProviderSubject('Google', 'subject-1', self::MEMBER));
    }

    private function persist(string $userClass, string $userId): void
    {
        $account = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('subject-1')
            ->setUserClass($userClass)
            ->setUserId($userId)
            ->setUserIdentifier('user' . $userId . '@example.com');

        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
}
