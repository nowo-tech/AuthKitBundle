<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlProviders;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\WebauthnSqlScript;
use Nowo\AuthKitBundle\Transaction\AuthKitNamedLock;
use Nowo\TransactionContract\TransientStorageFailure;

use function sprintf;
use function str_starts_with;

/**
 * 2.10.0 on MySQL, MariaDB and PostgreSQL: the shipped SAML table scripts match the mapping, and a first SAML sign-in
 * (just-in-time creation of `saml:<provider>`) waits for a concurrent one holding the identity lock: past the lock
 * timeout it is a transient failure (409 CONFLICT_001 at the edge) that wrote nothing and left no transaction open;
 * once the peer is gone it creates one account and one link, and a second sign-in finds them.
 */
final class SamlDatabaseTest extends LoadDatabaseTestCase
{
    public function testTheShippedScriptsCreateExactlyTheMappedTable(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE ' . SamlProvider::TABLE);
        WebauthnSqlScript::run($connection, 'up', 'saml');
        self::assertSame('', WebauthnSqlScript::differences($this->em, 'saml'), 'no difference with the mapping on ' . WebauthnSqlScript::platform($connection));
        $this->useSamlKernel();
        $this->em->persist(SamlProviders::acme());
        $this->em->flush();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM ' . SamlProvider::TABLE));
        WebauthnSqlScript::run($connection, 'down', 'saml');
        self::assertFalse($connection->createSchemaManager()->tablesExist([SamlProvider::TABLE]));
        WebauthnSqlScript::run($connection, 'up', 'saml');
        $this->assertHealthy();
    }

    public function testAFirstSamlSignInWaitsForAConcurrentOneAndCreatesOneAccount(): void
    {
        $this->useSamlKernel();
        $lock   = 'social:saml:acme' . "\0" . 'subject-1' . "\0" . KernelUser::class;
        $answer = $this->otherSession()->value($this->mysql()
            ? sprintf("SELECT GET_LOCK('%s', 10)", AuthKitNamedLock::name($lock))
            : sprintf('SELECT pg_advisory_lock(%d)', AuthKitNamedLock::advisoryKey($lock)));
        self::assertTrue(str_starts_with($answer, 'VAL'), $answer);

        try {
            $this->linkOrCreate();
            self::fail('The peer holds the identity lock.');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM kernel_users'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM auth_kit_social_account'));
        $this->assertHealthy();

        self::assertSame('OK', $this->otherSession()->execute($this->mysql()
            ? sprintf("SELECT RELEASE_LOCK('%s')", AuthKitNamedLock::name($lock))
            : sprintf('SELECT pg_advisory_unlock(%d)', AuthKitNamedLock::advisoryKey($lock))));
        $first  = $this->linkOrCreate();
        $second = $this->linkOrCreate();
        self::assertSame(['grace@example.com', 'grace@example.com'], [$first, $second]);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'grace@example.com'"));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM auth_kit_social_account WHERE provider = 'saml:acme'"));
        $this->assertHealthy();
    }

    private function linkOrCreate(): string
    {
        $profile = $this->get('load.profiles', ProfileRegistry::class)->getDefault();
        $social  = new SocialUserProfile('subject-1', 'grace@example.com', 'Grace', ['email' => 'grace@example.com'], true, 'https://idp.example.com/saml');

        return $this->get('load.social', SocialAccountLinker::class)->linkOrCreate($profile, 'saml:acme', $social, ['access_token' => '', 'refresh_token' => null, 'expires_in' => null])->getUserIdentifier();
    }

    private function useSamlKernel(): void
    {
        $this->kernel->shutdown();
        $this->kernel = new DatabaseTestKernel('test_saml', true);
        $this->kernel->boot();
        $this->em = $this->get('doctrine.orm.entity_manager', EntityManagerInterface::class);
    }
}
