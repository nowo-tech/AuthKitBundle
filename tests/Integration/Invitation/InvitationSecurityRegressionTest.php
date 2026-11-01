<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_map;
use function count;

use const PHP_URL_PATH;

/**
 * SEC-004 Appendix CD regressions (PoCs of the independent audit), with `show_link_when_undelivered: true` and a mailer
 * that fails (the "link shown once" path):
 *
 * - M1: an administrator without `ROLE_SUPER_ADMIN` can neither resend, re-invite (merge) nor revoke an invitation
 *   carrying it, never sees its link, and so cannot create that account;
 * - L2: an invitation never creates a case-variant duplicate of an existing account;
 * - L3: `InvitationManager::revokeOpenBy()` revokes a demoted inviter's open invitations;
 * - I5: invite → revoke → invite of one address is bounded by `resend_rate_limit` (per address).
 */
final class InvitationSecurityRegressionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return InvitationsShowLinkTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('admin@example.com')->setPassword('admin-password-123')->setRoles(['ROLE_ADMIN']));
        $em->persist((new KernelUser())->setEmail('super@example.com')->setPassword('super-password-123')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $em->persist((new KernelUser())->setEmail('Mixed@Example.com')->setPassword('mixed-password-123'));
        $em->flush();
        $em->clear();
    }

    /** M1 — resend through the administration: no button, and a forced POST shows no link. */
    public function testResendOfAHigherRoleInvitationIsRefusedAndShowsNoLink(): void
    {
        $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('target@example.com', ['ROLE_EDITOR', 'ROLE_SUPER_ADMIN']));
        $this->manager()->invite($this->user('admin@example.com'), 'default', new InvitationRequest('editor@example.com', ['ROLE_EDITOR']));
        $target = $this->invitation('target@example.com')->getPublicId();
        $editor = $this->invitation('editor@example.com')->getPublicId();

        $this->client->loginUser($this->user('admin@example.com'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        self::assertCount(0, $crawler->filter('form[action="/admin/invitations/' . $target . '/resend"]'), 'No Resend on an invitation granting a role the administrator lacks.');
        self::assertCount(0, $crawler->filter('form[action="/admin/invitations/' . $target . '/revoke"]'));
        $resendToken = (string) $crawler->filter('form[action="/admin/invitations/' . $editor . '/resend"] input[name="csrf_only[_token]"]')->attr('value');
        $revokeToken = (string) $crawler->filter('form[action="/admin/invitations/' . $editor . '/revoke"] input[name="csrf_only[_token]"]')->attr('value');
        self::assertNotSame('', $resendToken);

        // The invitation gains ROLE_SUPER_ADMIN after the page rendered (e.g. a concurrent re-invite by a super admin).
        $this->em()->createQuery('UPDATE ' . Invitation::class . ' i SET i.roles = :roles WHERE i.publicId = :id')
            ->setParameter('roles', '["ROLE_SUPER_ADMIN"]')->setParameter('id', $editor)->execute();
        $this->mailer()->fail = true;
        $sent                 = count($this->mailer()->sent);

        $this->client->request('POST', '/admin/invitations/' . $editor . '/resend', ['csrf_only' => ['_token' => $resendToken]]);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'it grants a role you do not hold');
        self::assertStringNotContainsString('/invitation/', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', '/admin/invitations/' . $editor . '/revoke', ['csrf_only' => ['_token' => $revokeToken]]);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->invitation('editor@example.com')->getRevokedAt(), 'Revoke refused too.');
        self::assertCount($sent, $this->mailer()->sent);

        // API: refused for the lower administrator, allowed for the super administrator (link shown to them only).
        $admin      = $this->user('admin@example.com');
        $superUser  = $this->user('super@example.com');
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'target@example.com']);
        self::assertInstanceOf(Invitation::class, $invitation);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->resend($admin, $invitation)->status);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->revoke($admin, $invitation)->status);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->resend(null, $invitation)->status, 'No actor holds no role.');
        $super = $this->manager()->resend($superUser, $invitation);
        self::assertSame(InvitationOutcome::LINK, $super->status);
        self::assertNull($this->find('target@example.com'));
    }

    /** M1 — re-invite (merge) of a higher-role invitation: refused, roles and message untouched, no link. */
    public function testReinviteMergeOfAHigherRoleInvitationIsRefused(): void
    {
        $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('t2@example.com', ['ROLE_SUPER_ADMIN'], message: 'From super'));
        $this->client->loginUser($this->user('admin@example.com'));
        $this->mailer()->fail = true;

        $crawler = $this->client->request('GET', '/admin/invitations/new');
        $this->client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form([
            'invitation[email]'   => 'T2@example.com',
            'invitation[message]' => 'Spoofed',
        ]));
        self::assertResponseStatusCodeSame(303);
        self::assertStringNotContainsString('/invitation/', (string) $this->client->getResponse()->getContent());
        $invitation = $this->invitation('t2@example.com');
        self::assertSame(['ROLE_SUPER_ADMIN'], $invitation->getRoles());
        self::assertSame('From super', $invitation->getMessage());
        self::assertSame(1, $invitation->getSendCount());

        // An actor may not hand out a role it lacks through a new invitation either (API).
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->invite($this->user('admin@example.com'), 'default', new InvitationRequest('t3@example.com', ['ROLE_SUPER_ADMIN']))->status);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->invite(null, 'default', new InvitationRequest('t3@example.com', ['ROLE_EDITOR']))->status);
        self::assertCount(0, $this->em()->getRepository(Invitation::class)->findBy(['email' => 't3@example.com']));
    }

    /** M1 — a link is never handed to an actor lacking a role, even when the invitation itself is in reach. */
    public function testLinkIsShownOnlyToAnActorHoldingEveryRole(): void
    {
        $this->mailer()->fail = true;
        $own                  = $this->manager()->invite($this->user('admin@example.com'), 'default', new InvitationRequest('own@example.com', ['ROLE_EDITOR']));
        self::assertSame(InvitationOutcome::LINK, $own->status, 'ROLE_EDITOR is held through the hierarchy.');
        self::assertNotNull($own->url);
    }

    /** L2 — an invitation for `mixed@example.com` never creates a twin of `Mixed@Example.com`. */
    public function testCaseVariantDuplicateAccountIsRefused(): void
    {
        $this->manager()->invite($this->user('admin@example.com'), 'default', new InvitationRequest('MIXED@example.COM'));
        $path = (string) parse_url((string) $this->mailer()->last()?->acceptUrl, PHP_URL_PATH);
        $this->client->request('GET', $path);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        $this->em()->clear();
        $accounts = array_map(static fn (KernelUser $u): string => $u->getEmail(), $this->em()->getRepository(KernelUser::class)->findAll());
        self::assertNotContains('mixed@example.com', $accounts);
        self::assertContains('Mixed@Example.com', $accounts);
        self::assertNull($this->invitation('mixed@example.com')->getAcceptedAt());
    }

    /** L3 — the host revokes the open invitations of a demoted inviter. */
    public function testRevokeOpenByInviter(): void
    {
        $super = $this->user('super@example.com');
        $this->manager()->invite($super, 'default', new InvitationRequest('s1@example.com', ['ROLE_SUPER_ADMIN']));
        $this->manager()->invite($super, 'default', new InvitationRequest('s2@example.com'));
        $this->manager()->invite($this->user('admin@example.com'), 'default', new InvitationRequest('a1@example.com'));

        self::assertSame(2, $this->manager()->revokeOpenBy('super@example.com', null, 'default'));
        self::assertNotNull($this->invitation('s1@example.com')->getRevokedAt());
        self::assertNotNull($this->invitation('s2@example.com')->getRevokedAt());
        self::assertNull($this->invitation('a1@example.com')->getRevokedAt());
        self::assertCount(2, array_filter($this->listener()->events, static fn (object $e): bool => $e instanceof InvitationRevokedEvent));
        self::assertSame(0, $this->manager()->revokeOpenBy('super@example.com'));
    }

    /** I5 — invite → revoke → invite of one address: at most resend_rate_limit + 1 sends per window. */
    public function testInviteRevokeLoopIsBoundedPerAddress(): void
    {
        $admin    = $this->user('admin@example.com');
        $statuses = [];
        for ($i = 0; $i < 6; ++$i) {
            $outcome    = $this->manager()->invite($admin, 'default', new InvitationRequest('victim@example.com'));
            $statuses[] = $outcome->status;
            $open       = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'victim@example.com', 'revokedAt' => null]);
            if ($open instanceof Invitation) {
                self::assertSame(InvitationOutcome::REVOKED, $this->manager()->revoke($admin, $open)->status);
            }
        }
        self::assertSame([
            InvitationOutcome::SENT, InvitationOutcome::SENT, InvitationOutcome::SENT, InvitationOutcome::SENT,
            InvitationOutcome::RATE_LIMITED, InvitationOutcome::RATE_LIMITED,
        ], $statuses);
    }

    private function invitation(string $email): Invitation
    {
        $this->em()->clear();
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => $email], ['id' => 'DESC']);
        self::assertInstanceOf(Invitation::class, $invitation);

        return $invitation;
    }

    private function find(string $email): ?KernelUser
    {
        $this->em()->clear();

        return $this->em()->getRepository(KernelUser::class)->find($email);
    }

    private function user(string $email): KernelUser
    {
        $user = $this->find($email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    private function manager(): InvitationManager
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);

        return $manager;
    }

    private function mailer(): CapturingInvitationMailer
    {
        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);

        return $mailer;
    }

    private function listener(): CapturingInvitationListener
    {
        $listener = self::getContainer()->get(CapturingInvitationListener::class);
        self::assertInstanceOf(CapturingInvitationListener::class, $listener);

        return $listener;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
