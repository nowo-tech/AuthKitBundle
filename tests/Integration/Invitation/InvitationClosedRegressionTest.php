<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-004 Appendix CD regression — I7: with `registration_mode: disabled` (acceptance closed) no link is sent (`CLOSED`).
 */
final class InvitationClosedRegressionTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return InvitationsClosedTestKernel::class;
    }

    public function testClosedRegistrationSendsNoLink(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $admin = (new KernelUser())->setEmail('admin@example.com')->setPassword('admin-password-123')->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/invitations/new', [], [], []);
        $client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => 'ana@example.com']), [], []);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Invitations are closed');
        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);
        self::assertNull($mailer->last());
        $em->clear();
        self::assertCount(0, $em->getRepository(Invitation::class)->findAll());
    }
}
