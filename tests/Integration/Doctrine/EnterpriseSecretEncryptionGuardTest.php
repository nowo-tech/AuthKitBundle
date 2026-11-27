<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\EnterpriseSecretEncryptionGuard;
use Nowo\AuthKitBundle\Doctrine\EnterpriseSecretNotEncryptedException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_decode;
use function base64_encode;
use function str_contains;
use function str_ends_with;
use function substr;

/**
 * 2.8.0 (fail closed): an enterprise client secret is written encrypted by nowo-tech/doctrine-encrypt-bundle, or not
 * at all.
 */
final class EnterpriseSecretEncryptionGuardTest extends TestCase
{
    private const BUNDLE_ENTITIES = ['Nowo\\AuthKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    /**
     * SECURITY: without encryption the insert is refused before the flush opens a transaction; nothing is written and
     * the manager stays usable. The message names the credential, never the secret.
     */
    public function testAnEnterpriseSecretIsNotInsertedWithoutEncryption(): void
    {
        $em = $this->manager(false);
        $em->persist($this->credential('org-acme')->setEnterpriseSso(true));

        try {
            $em->flush();
            self::fail('The unencrypted enterprise secret was written.');
        } catch (EnterpriseSecretNotEncryptedException $exception) {
            self::assertStringContainsString('"org-acme"', $exception->getMessage());
            self::assertFalse(str_contains($exception->getMessage(), 'secret-placeholder'));
        }

        self::assertTrue($em->isOpen());
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_social_credential'));
    }

    public function testAConsumerCredentialToBecomeEnterpriseIsRefusedToo(): void
    {
        $em = $this->manager(false);
        $em->persist($credential = $this->credential('google'));
        $em->flush();

        $credential->setEnterpriseSso(true);
        $this->expectException(EnterpriseSecretNotEncryptedException::class);
        $em->flush();
    }

    public function testConsumerCredentialsAndEnterpriseOnesWithoutSecretAreNotAffected(): void
    {
        $em = $this->manager(false);
        $em->persist($this->credential('google'));
        $em->persist($this->credential('org-public')->setEnterpriseSso(true)->setClientSecret(''));
        $em->flush();

        self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_social_credential'));
    }

    public function testWithEncryptionTheSecretIsStoredEncrypted(): void
    {
        $em = $this->manager(true);
        $em->persist($credential = $this->credential('org-acme')->setEnterpriseSso(true));
        $em->flush();
        $credential->setLabel('Acme SSO');
        $em->flush();

        $stored = (string) $em->getConnection()->fetchOne('SELECT client_secret FROM auth_kit_social_credential');
        self::assertTrue(str_ends_with($stored, DoctrineEncryptSubscriber::ENCRYPTION_MARKER), $stored);
        self::assertFalse(str_contains($stored, 'secret-placeholder'));
        self::assertTrue(EnterpriseSecretEncryptionGuard::encrypts($em));
    }

    /**
     * SECURITY: the listener registered with its encryptor switched off (as the decrypt commands do) is no encryption.
     */
    public function testAListenerWithoutEncryptorIsNoEncryption(): void
    {
        $em       = $this->manager(true);
        $listener = null;
        foreach ($em->getEventManager()->getListeners(Events::onFlush) as $candidate) {
            if ($candidate instanceof DoctrineEncryptSubscriber) {
                $listener = $candidate;
            }
        }
        self::assertInstanceOf(DoctrineEncryptSubscriber::class, $listener);
        $listener->setEncryptor();

        self::assertFalse(EnterpriseSecretEncryptionGuard::encrypts($em));
        $em->persist($this->credential('org-acme')->setEnterpriseSso(true));
        $this->expectException(EnterpriseSecretNotEncryptedException::class);
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
        $em->getEventManager()->addEventListener([Events::onFlush], new EnterpriseSecretEncryptionGuard());
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(SocialLoginCredential::class)]);

        return $em;
    }

    private function credential(string $provider): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider($provider)->setLabel($provider)->setClientId('client-placeholder')->setClientSecret('secret-placeholder');
    }
}
