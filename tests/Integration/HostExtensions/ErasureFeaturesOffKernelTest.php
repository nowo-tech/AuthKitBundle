<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GY-L5: rows of features turned off, and social links, go on erasure; another person's stay; missing tables are fine.
 */
final class ErasureFeaturesOffKernelTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private KernelUser $subject;

    protected static function getKernelClass(): string
    {
        return ErasureFeaturesOffTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $this->subject = (new KernelUser())->setEmail('subject@example.com')->setPassword('x');
        $other         = (new KernelUser())->setEmail('other@example.com')->setPassword('x');
        $em->persist($this->subject);
        $em->persist($other);
        $em->flush();
        foreach ([$this->subject, $other] as $user) {
            $this->rowsFor($user);
        }
        $em->flush();
    }

    public function testErasesTheRowsOfFeaturesThatAreOffAndOnlyTheSubjects(): void
    {
        self::assertSame(2, $this->rowCount(MfaCredential::TABLE));

        $ran = $this->eraser()->erase($this->subject);

        self::assertContains('auth_kit_mfa', $ran);
        self::assertContains('auth_kit_account_switcher', $ran);
        self::assertContains('auth_kit_social_account', $ran);
        self::assertSame(1, $this->rowCount(MfaCredential::TABLE));
        self::assertSame(1, $this->rowCount(MfaRecoveryCode::TABLE));
        self::assertSame(1, $this->rowCount(AccountVaultEntry::TABLE));
        self::assertSame(1, $this->rowCount('auth_kit_social_account'));
        self::assertSame('other@example.com', $this->em->getConnection()->fetchOne('SELECT user_identifier FROM ' . AccountVaultEntry::TABLE));
        self::assertSame('other@example.com', $this->em->getConnection()->fetchOne('SELECT user_identifier FROM auth_kit_social_account'));
    }

    public function testMissingTablesAreTolerated(): void
    {
        foreach ([MfaRecoveryCode::TABLE, 'auth_kit_mfa_trusted_device', MfaCredential::TABLE, AccountVaultEntry::TABLE, 'auth_kit_social_account'] as $table) {
            $this->em->getConnection()->executeStatement('DROP TABLE ' . $table);
        }

        self::assertContains('auth_kit_social_account', $this->eraser()->erase($this->subject));
    }

    private function rowsFor(KernelUser $user): void
    {
        $now        = new DateTimeImmutable();
        $ids        = $this->em->getClassMetadata(KernelUser::class)->getIdentifierValues($user);
        $id         = (string) reset($ids);
        $credential = new MfaCredential('default', KernelUser::class, $id, $now);
        $this->em->persist($credential);
        $this->em->flush();
        $this->em->persist(new MfaRecoveryCode((int) $credential->getId(), hash('sha256', $user->getEmail()), $now));
        $this->em->persist(new AccountVaultEntry(bin2hex(random_bytes(16)), 'default', $user->getEmail(), hash('sha256', $user->getEmail()), null, $now, null, null, null, $now, $now, $now->modify('+1 day')));
        $this->em->persist((new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('subject-' . $id)
            ->setUserClass(KernelUser::class)
            ->setUserId($id)
            ->setUserIdentifier($user->getEmail())
            ->setRawProfile([]));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function eraser(): PersonalDataEraser
    {
        $eraser = self::getContainer()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        return $eraser;
    }
}
