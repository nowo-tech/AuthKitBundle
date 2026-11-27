<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\EncryptedFieldWrite;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Tests\Support\SingleManagerRegistry;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function str_ends_with;
use function substr;

/**
 * 2.9.0: reading a row with an `#[Encrypted]` property never leaves the unit of work dirty. DoctrineEncrypt writes the
 * decrypted value (postLoad, postFlush) and the stored ciphertext (preFlush) through the entity's setter; those writes
 * used to stamp `updatedAt`, so a read row was scheduled for update (a host transaction runner refused the unit of
 * work) and every flush rewrote `updated_at`.
 */
final class EncryptedReadKeepsRowCleanTest extends TestCase
{
    private const BUNDLE_ENTITIES = ['Nowo\\AuthKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    private const STORED_UPDATED_AT = '2020-01-01 00:00:00';

    /**
     * @return iterable<string, array{bool}>
     */
    public static function secretStorage(): iterable
    {
        yield 'encrypted secret' => [true];
        yield 'plain secret' => [false];
    }

    #[DataProvider('secretStorage')]
    public function testReadingACredentialLeavesItClean(bool $encrypted): void
    {
        $em = $this->manager($encrypted);
        $em->persist((new SocialLoginCredential())->setProvider('org-acme')->setLabel('Acme')->setClientId('client-placeholder')
            ->setClientSecret('secret-placeholder')->setEnterpriseSso(true)->setScopes(['openid']));
        $em->flush();
        $em->clear(); // the test's own manager: a fresh load, as in a new request
        $em->getConnection()->executeStatement('UPDATE auth_kit_social_credential SET updated_at = ?', [self::STORED_UPDATED_AT]);
        self::assertSame($encrypted, str_ends_with((string) $em->getConnection()->fetchOne('SELECT client_secret FROM auth_kit_social_credential'), DoctrineEncryptSubscriber::ENCRYPTION_MARKER));

        $registry   = new SingleManagerRegistry($em);
        $credential = (new SocialLoginCredentialRepository($registry))->findOneByProvider('org-acme');
        self::assertInstanceOf(SocialLoginCredential::class, $credential);
        self::assertSame('secret-placeholder', $credential->getClientSecret());
        self::assertSame(['openid'], $credential->getScopes());
        self::assertSame(self::STORED_UPDATED_AT, $credential->getUpdatedAt()->format('Y-m-d H:i:s'));

        $this->assertClean($em, $credential);
        self::assertSame(self::STORED_UPDATED_AT, $em->getConnection()->fetchOne('SELECT updated_at FROM auth_kit_social_credential'));

        // Read again through the repository (refresh hint): still clean.
        self::assertSame($credential, (new SocialLoginCredentialRepository($registry))->findOneByProvider('org-acme'));
        $this->assertClean($em, $credential);
    }

    #[DataProvider('secretStorage')]
    public function testReadingASocialAccountLeavesItClean(bool $encrypted): void
    {
        $em = $this->manager($encrypted);
        $em->persist((new SocialLoginAccount())->setProvider('google')->setProviderUserId('subject-1')->setUserClass('App\\User')
            ->setUserId('1')->setUserIdentifier('user@example.test')->setAccessToken('access-placeholder')->setRefreshToken('refresh-placeholder'));
        $em->flush();
        $em->clear();
        $em->getConnection()->executeStatement('UPDATE auth_kit_social_account SET updated_at = ?', [self::STORED_UPDATED_AT]);

        $account = (new SocialLoginAccountRepository(new SingleManagerRegistry($em)))->findOneBy(['providerUserId' => 'subject-1']);
        self::assertInstanceOf(SocialLoginAccount::class, $account);
        self::assertSame('access-placeholder', $account->getAccessToken());
        self::assertSame('refresh-placeholder', $account->getRefreshToken());

        $this->assertClean($em, $account);
        self::assertSame(self::STORED_UPDATED_AT, $em->getConnection()->fetchOne('SELECT updated_at FROM auth_kit_social_account'));
    }

    public function testARealSecretChangeStillStampsUpdatedAt(): void
    {
        $credential = new SocialLoginCredential();
        $before     = $credential->setClientSecret('secret-placeholder')->getUpdatedAt();
        self::assertSame($before, $credential->setClientSecret('secret-placeholder')->getUpdatedAt(), 'The same value is no change.');
        self::assertNotSame($before, $credential->setClientSecret('another-placeholder')->getUpdatedAt());

        $account = new SocialLoginAccount();
        $before  = $account->getUpdatedAt();
        self::assertSame($before, $account->setAccessToken(null)->setRefreshToken(null)->getUpdatedAt());
        self::assertNotSame($before, $account->setRefreshToken('refresh-placeholder')->getUpdatedAt());
    }

    public function testTheStoredFormIsNoChange(): void
    {
        $stored = 'cGF5bG9hZA==' . DoctrineEncryptSubscriber::ENCRYPTION_MARKER;
        self::assertFalse(EncryptedFieldWrite::changes($stored, 'plain'));
        self::assertFalse(EncryptedFieldWrite::changes('plain', $stored));
        self::assertFalse(EncryptedFieldWrite::changes('plain', 'plain'));
        self::assertFalse(EncryptedFieldWrite::changes(null, null));
        self::assertTrue(EncryptedFieldWrite::changes('plain', 'other'));
        self::assertTrue(EncryptedFieldWrite::changes(null, 'plain'));
        self::assertTrue(EncryptedFieldWrite::changes(DoctrineEncryptSubscriber::ENCRYPTION_MARKER, 'plain'), 'The marker alone is no ciphertext.');
    }

    /**
     * What a flush does first (DoctrineEncrypt's preFlush puts the stored ciphertext back), then the change sets a
     * transaction runner inspects; then a real flush, which must write nothing, and the same check after it (postFlush
     * decrypts again).
     */
    private function assertClean(EntityManagerInterface $em, object $entity): void
    {
        $unitOfWork = $em->getUnitOfWork();
        $em->getEventManager()->dispatchEvent(Events::preFlush, new PreFlushEventArgs($em));
        $unitOfWork->computeChangeSets();
        self::assertFalse($unitOfWork->isScheduledForUpdate($entity), 'A read scheduled the row for update.');
        self::assertSame([], $unitOfWork->getEntityChangeSet($entity));

        $em->flush();
        $em->getEventManager()->dispatchEvent(Events::preFlush, new PreFlushEventArgs($em));
        $unitOfWork->computeChangeSets();
        self::assertFalse($unitOfWork->isScheduledForUpdate($entity), 'The flush left the row dirty.');
        $em->flush();
    }

    private function manager(bool $encrypted): EntityManagerInterface
    {
        $em = (new SqliteManagerRegistry(['default' => self::BUNDLE_ENTITIES]))->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if ($encrypted) {
            $em->getEventManager()->addEventListener(
                [Events::preFlush, Events::onFlush, Events::postFlush, Events::postLoad, Events::preUpdate, Events::postUpdate, Events::prePersist],
                new DoctrineEncryptSubscriber(new class implements EncryptorInterface {
                    public function encrypt(#[SensitiveParameter] string $data): string
                    {
                        return base64_encode('x' . $data);
                    }

                    public function decrypt(string $data): string
                    {
                        return substr((string) base64_decode($data, true), 1);
                    }
                }),
            );
        }
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(SocialLoginCredential::class), $em->getClassMetadata(SocialLoginAccount::class)]);

        return $em;
    }
}
