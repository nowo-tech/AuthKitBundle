<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `show_link_when_undelivered: true`: an invitation whose e-mail could not be sent shows its link once, never cached.
 */
final class InvitationLinkKernelTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return InvitationsShowLinkTestKernel::class;
    }

    public function testUndeliveredInvitationShowsTheLinkOnce(): void
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

        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);
        $mailer->fail = true;

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/invitations/new');
        $client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => 'dan@example.com']));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('[data-testid="nowo-auth-kit-invitation-link"]', 'https://app.example.test/invitation/');
        self::assertSelectorExists('[data-testid="nowo-auth-kit-invitation-link-warning"]');
        self::assertSelectorNotExists('[data-testid="nowo-auth-kit-invitation-link"] a[href*="/invitation/"]');

        $listener = self::getContainer()->get(CapturingInvitationListener::class);
        self::assertInstanceOf(CapturingInvitationListener::class, $listener);
        $created = $listener->events[0] ?? null;
        self::assertInstanceOf(InvitationCreatedEvent::class, $created);
        self::assertSame(InvitationCreatedEvent::DELIVERY_LINK, $created->delivery);
    }
}
