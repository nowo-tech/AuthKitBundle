<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\PersonalData\DoctrineSocialAccountPersonalDataExporter;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataSubject;
use Nowo\AuthKitBundle\Tests\Support\PersonalDataDatabase;
use Nowo\AuthKitBundle\Tests\Support\SingleManagerRegistry;
use PHPUnit\Framework\TestCase;

use function abs;
use function array_column;
use function json_encode;
use function memory_get_usage;

/**
 * The social-link export port on a real database (SQLite here; PostgreSQL and MySQL in the CI job `real-databases`).
 */
final class SocialAccountPersonalDataExportTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = PersonalDataDatabase::entityManager();
    }

    public function testExportsTheLinksOfTheOwnerWithoutSecrets(): void
    {
        $github = $this->link('github', self::USER, '7', 'alice@example.com', 'alice@users.example', 'Alice');
        $this->link('google', self::USER, '7', 'alice@example.com', null, null);
        $this->link('google', self::USER, '8', 'bob@example.com', 'bob@example.com', 'Bob');
        $this->link('google', 'App\\Entity\\Customer', '7', 'customer@example.com', 'customer@example.com', 'Customer');

        $links = $this->exporter()->export(SocialAccountPersonalDataSubject::fromUserId(self::USER, '7'));

        self::assertSame(['github', 'google'], array_column($links, 'provider'));
        // MySQL rounds the fractional second: compare the ATOM string to the second.
        $linkedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, (string) $links[0]['linked_at']);
        self::assertInstanceOf(DateTimeImmutable::class, $linkedAt);
        self::assertLessThanOrEqual(1, abs($linkedAt->getTimestamp() - $github->getCreatedAt()->getTimestamp()));
        self::assertSame([
            'provider'       => 'github',
            'linked_at'      => $links[0]['linked_at'],
            'provider_email' => 'alice@users.example',
            'display_name'   => 'Alice',
        ], $links[0]);
        self::assertNull($links[1]['provider_email']);
        $json = (string) json_encode($links);
        foreach (['provider-subject', 'access-REDACTED-TEST', 'refresh-REDACTED-TEST', 'raw-REDACTED-TEST'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testLegacyRowsNeedBothColumnsAndExactValues(): void
    {
        $this->link('github', self::USER, 'alice', 'alice', 'a@example.com', null);
        $this->link('gitlab', self::USER, 'alice', 'bob', 'b@example.com', null);
        $this->link('google', self::USER, 'Alice', 'Alice', 'c@example.com', null);
        $this->link('google', self::USER, 'jose', 'jose', 'd@example.com', null);

        self::assertSame(['github'], array_column($this->exporter()->export(SocialAccountPersonalDataSubject::fromLegacyIdentifier(self::USER, 'alice')), 'provider'));
        self::assertSame([], $this->exporter()->export(SocialAccountPersonalDataSubject::fromLegacyIdentifier(self::USER, 'josé')));
        self::assertSame([], $this->exporter()->export(SocialAccountPersonalDataSubject::fromUserId(self::USER, 'ALICE')));
    }

    public function testWithoutTableOrMappingNothingIsExported(): void
    {
        $subject = SocialAccountPersonalDataSubject::fromUserId(self::USER, '7');
        self::assertSame([], (new DoctrineSocialAccountPersonalDataExporter($this->createStub(ManagerRegistry::class)))->export($subject));

        $this->em->getConnection()->executeStatement('DROP TABLE auth_kit_social_account');
        self::assertSame([], $this->exporter()->export($subject));
    }

    /** FrankenPHP worker mode: one service instance, two people, many runs, no leak, flat memory. */
    public function testWorkerModeRunsKeepNoState(): void
    {
        $this->link('github', self::USER, '1', 'a@example.com', 'a@example.com', 'A');
        $this->link('google', self::USER, '2', 'b@example.com', 'b@example.com', 'B');
        $exporter = $this->exporter();
        $a        = SocialAccountPersonalDataSubject::fromUserId(self::USER, '1');
        $b        = SocialAccountPersonalDataSubject::fromUserId(self::USER, '2');
        $expectA  = $exporter->export($a);
        $expectB  = $exporter->export($b);
        self::assertSame(['github'], array_column($expectA, 'provider'));
        self::assertSame(['google'], array_column($expectB, 'provider'));
        $runs   = PersonalDataDatabase::isRealServer() ? 50 : 1000;
        $before = 0;
        for ($i = 0; $i < $runs; ++$i) {
            if ($i === 10) {
                $before = memory_get_usage();
            }
            self::assertSame($expectA, $exporter->export($a));
            self::assertSame($expectB, $exporter->export($b));
        }
        self::assertLessThan(1024 * 1024, memory_get_usage() - $before);
    }

    private function exporter(): DoctrineSocialAccountPersonalDataExporter
    {
        $doctrine = new SingleManagerRegistry($this->em);

        return new DoctrineSocialAccountPersonalDataExporter($doctrine);
    }

    private function link(string $provider, string $class, string $userId, string $identifier, ?string $email, ?string $name): SocialLoginAccount
    {
        $account = (new SocialLoginAccount())
            ->setProvider($provider)
            ->setProviderUserId('provider-subject-' . $provider . $userId . $class)
            ->setUserClass($class)
            ->setUserId($userId)
            ->setUserIdentifier($identifier)
            ->setEmail($email)
            ->setDisplayName($name)
            ->setAccessToken('access-REDACTED-TEST')
            ->setRefreshToken('refresh-REDACTED-TEST')
            ->setRawProfile(['note' => 'raw-REDACTED-TEST']);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
