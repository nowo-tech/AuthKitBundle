<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\PersonalData\DoctrineInvitationPersonalData;
use Nowo\AuthKitBundle\PersonalData\DoctrineSocialAccountPersonalDataExporter;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitEraser;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitExportProvider;
use Nowo\AuthKitBundle\PersonalData\SocialAccountUserKitExportProvider;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_column;
use function bin2hex;
use function hash;
use function json_encode;
use function random_bytes;

/**
 * The GDPR ports through UserKit (1.45): invitations and social login are off, the rows are still erased and exported,
 * before the host identity eraser (priority 90) renames the e-mail.
 */
class GdprPortsKernelTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private KernelUser $subject;

    protected static function getKernelClass(): string
    {
        return GdprPortsTestKernel::class;
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
        $em->persist($this->subject);
        $em->persist((new KernelUser())->setEmail('other@example.com')->setPassword('x'));
        $now = new DateTimeImmutable();
        foreach ([['subject@example.com', 'boss@example.com'], ['friend@example.com', 'subject@example.com'], ['other@example.com', 'boss@example.com']] as [$email, $by]) {
            $invitation = new Invitation('default', $email, $by, 7, $now);
            $invitation->issue(hash('sha256', bin2hex(random_bytes(16))), 7, $now);
            $em->persist($invitation);
        }
        foreach (['subject@example.com', 'other@example.com'] as $owner) {
            $em->persist((new SocialLoginAccount())
                ->setProvider('github')
                ->setProviderUserId('gh-' . $owner)
                ->setUserClass(KernelUser::class)
                ->setUserId($owner)
                ->setUserIdentifier($owner)
                ->setEmail($owner)
                ->setRawProfile([]));
        }
        $em->flush();
    }

    public function testTheServicesAreRegistered(): void
    {
        self::assertInstanceOf(DoctrineInvitationPersonalData::class, self::getContainer()->get('test.invitation_eraser'));
        self::assertInstanceOf(DoctrineInvitationPersonalData::class, self::getContainer()->get('test.invitation_exporter'));
        self::assertInstanceOf(DoctrineSocialAccountPersonalDataExporter::class, self::getContainer()->get('test.social_exporter'));
        self::assertTrue(self::getContainer()->has(InvitationUserKitEraser::class));
        self::assertTrue(self::getContainer()->has(InvitationUserKitExportProvider::class));
        self::assertTrue(self::getContainer()->has(SocialAccountUserKitExportProvider::class));
    }

    public function testExportHasTheInvitationsAndSocialAccountsSections(): void
    {
        $exporter = self::getContainer()->get('test.personal_data.exporter');
        self::assertInstanceOf(PersonalDataExporter::class, $exporter);

        $sections = $exporter->export($this->subject)['sections'];

        self::assertIsArray($sections['invitations']);
        self::assertSame(['default'], array_column($sections['invitations']['received'], 'profile'));
        self::assertCount(1, $sections['invitations']['sent']);
        self::assertStringNotContainsString('friend@example.com', (string) json_encode($sections['invitations']), 'The invitee is not the subject.');
        self::assertIsArray($sections['social_accounts']);
        self::assertSame([['provider' => 'github', 'linked_at' => $sections['social_accounts'][0]['linked_at'], 'provider_email' => 'subject@example.com', 'display_name' => null]], $sections['social_accounts']);
    }

    public function testErasureRunsBeforeTheIdentityEraserAndKeepsOtherPeoplesRows(): void
    {
        $eraser = self::getContainer()->get('test.personal_data.eraser');
        self::assertInstanceOf(PersonalDataEraser::class, $eraser);

        $ran = $eraser->erase($this->subject);

        self::assertContains(InvitationUserKitEraser::KEY, $ran);
        $connection = $this->em->getConnection();
        self::assertSame(['friend@example.com', 'other@example.com'], $connection->fetchFirstColumn('SELECT email FROM auth_kit_invitation ORDER BY id'));
        self::assertNull($connection->fetchOne("SELECT invited_by FROM auth_kit_invitation WHERE email = 'friend@example.com'"));
        self::assertNotNull($connection->fetchOne("SELECT revoked_at FROM auth_kit_invitation WHERE email = 'friend@example.com'"), 'The link of the open invitation stops working.');
        self::assertSame('boss@example.com', $connection->fetchOne("SELECT invited_by FROM auth_kit_invitation WHERE email = 'other@example.com'"));
        self::assertSame(['other@example.com'], $connection->fetchFirstColumn('SELECT user_id FROM auth_kit_social_account'));
    }
}
