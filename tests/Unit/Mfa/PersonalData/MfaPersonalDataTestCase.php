<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa\PersonalData;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

use function array_filter;
use function array_values;

/**
 * A real {@see MfaManager} over in-memory Doctrine doubles (1.36 personal-data unit tests): credentials are kept in a
 * list, DBAL statements are recorded. The {@see TestUser} is not a Doctrine entity, so its owner id is its e-mail.
 */
abstract class MfaPersonalDataTestCase extends TestCase
{
    /** @var list<array{sql: string, params: array<mixed>}> */
    protected array $statements = [];

    /** @var list<MfaCredential> */
    private array $credentials = [];

    private int $nextId = 1;

    private int $unusedCodes = 0;

    /** @var list<array<string, mixed>> */
    private array $deviceRows = [];

    /**
     * @param list<array<string, mixed>> $deviceRows rows of the trusted-device table
     */
    protected function stored(int $unusedCodes, array $deviceRows = []): void
    {
        $this->unusedCodes = $unusedCodes;
        $this->deviceRows  = $deviceRows;
    }

    protected function add(string $profile, string $userId, bool $enabled = false): MfaCredential
    {
        $credential = new MfaCredential($profile, TestUser::class, $userId, new DateTimeImmutable('2026-11-01 10:00:00'));
        (new ReflectionProperty(MfaCredential::class, 'id'))->setValue($credential, $this->nextId++);
        if ($enabled) {
            $credential->enable('PLACEHOLDERSECRET', 1, new DateTimeImmutable('2026-11-02 10:00:00'));
        }
        $this->credentials[] = $credential;

        return $credential;
    }

    protected function manager(bool $mfa = true): MfaManager
    {
        $repository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('findBy')->willReturnCallback(fn (array $criteria): array => $this->matching($criteria));
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?MfaCredential => $this->matching($criteria)[0] ?? null);

        $metadata = $this->getMockBuilder(ClassMetadata::class)->disableOriginalConstructor()->getMock();
        $metadata->method('getTableName')->willReturn('mfa_table');

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock();
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->statements[] = ['sql' => $sql, 'params' => $params];

            return 1;
        });
        $connection->method('fetchOne')->willReturn($this->unusedCodes);
        $connection->method('fetchAllAssociative')->willReturn($this->deviceRows);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $em = $this->getMockBuilder(EntityManagerInterface::class)->getMock();
        $em->method('getRepository')->willReturn($repository);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);
        $em->method('contains')->willReturn(false);

        $doctrine = $this->getMockBuilder(ManagerRegistry::class)->getMock();
        $doctrine->method('getManagerForClass')->willReturn($em);

        return new MfaManager(
            ProfileRegistryFactory::single(TestUser::class, $mfa ? ['mfa' => ['enabled' => true]] : []),
            new MfaStore($doctrine),
            new MfaOwnerResolver(),
            new MockClock('2026-11-05 10:00:00'),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
    }

    /**
     * @param array<string, string> $criteria
     *
     * @return list<MfaCredential>
     */
    private function matching(array $criteria): array
    {
        return array_values(array_filter($this->credentials, static fn (MfaCredential $credential): bool => $credential->owner()->type === $criteria['userType']
            && $credential->owner()->id === $criteria['userId']
            && (!isset($criteria['profile']) || $credential->getProfile() === $criteria['profile'])));
    }
}
