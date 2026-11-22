<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-004 Appendix CD regression — L1: `absolute_url.base_uri` from an env var that resolves empty at runtime never lets a link follow the request Host:
 * nothing is sent (`NOT_CONFIGURED`), nothing is stored.
 */
final class InvitationEnvBaseRegressionTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return InvitationsEnvBaseTestKernel::class;
    }

    public function testEmptyRuntimeBaseUriSendsNothingAndIgnoresTheHostHeader(): void
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

        $crawler = $client->request('GET', '/admin/invitations/new', [], [], ['HTTP_HOST' => 'evil.example']);
        $client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => 'ana@example.com']), [], ['HTTP_HOST' => 'evil.example']);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'absolute_url.base_uri');
        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);
        self::assertNull($mailer->last());
        $em->clear();
        self::assertCount(0, $em->getRepository(Invitation::class)->findAll());
    }
}
