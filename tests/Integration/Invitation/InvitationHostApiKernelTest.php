<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

use function count;
use function parse_url;
use function print_r;
use function strlen;
use function substr;
use function time;

use const PHP_URL_PATH;

/**
 * The 1.33 host API on a host-like configuration ({@see InvitationsHostApiTestKernel}): invitation registration role,
 * rejected-link event, pre-accept refusal with a reason, resend TTL and returned link, merge key with deep payload
 * merge, social acceptance of the pending invitation, no admin routes — and the SEC-004 CD M1 re-checks on them.
 */
final class InvitationHostApiKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return InvitationsHostApiTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('admin@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN']));
        $em->persist((new KernelUser())->setEmail('super@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $em->flush();
        $em->clear();
    }

    public function testAdminRoutesAreOffAndTheAcceptRouteStays(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        self::assertNotNull($routes->get('nowo_auth_kit_invitation_accept'));
        foreach (['nowo_auth_kit_invitations', 'nowo_auth_kit_invitations_new', 'nowo_auth_kit_invitations_resend', 'nowo_auth_kit_invitations_revoke'] as $name) {
            self::assertNull($routes->get($name), $name);
        }
        $this->client->loginUser($this->user('super@example.com'));
        $this->client->request('GET', '/admin/invitations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReturnedLinkAndInvitationRegistrationRole(): void
    {
        $mailer  = $this->mailer();
        $sent    = count($mailer->sent);
        $outcome = $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('new@example.com', ['ROLE_EDITOR'], returnLink: true));

        self::assertSame(InvitationOutcome::LINK, $outcome->status);
        self::assertStringStartsWith('https://app.example.test/invitation/', (string) $outcome->url);
        self::assertCount($sent, $mailer->sent, 'returnLink sends no e-mail.');
        $created = $this->listener()->events[0] ?? null;
        self::assertInstanceOf(InvitationCreatedEvent::class, $created);
        self::assertSame(InvitationCreatedEvent::DELIVERY_HOST, $created->delivery);

        $this->acceptThroughThePage((string) $outcome->url);
        self::assertResponseRedirects();
        $user = $this->user('new@example.com');
        self::assertContains('ROLE_MEMBER', $user->getRoles());
        self::assertContains('ROLE_EDITOR', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles(), 'registration_role (first user) is not handed to invitees.');
    }

    public function testHostRefusalsShowTheirReasonAndKeepNothing(): void
    {
        $url  = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('seat@example.com', returnLink: true))->url;
        $host = $this->hooks();

        $host->refuseBefore = new InvitationRefusal('host.no_seat_left', ['%org%' => 'Acme']);
        $this->acceptThroughThePage($url);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        self::assertStringContainsString('host.no_seat_left', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('seat@example.com'));
        self::assertNull($this->invitation('seat@example.com')->getAcceptedAt());

        $host->refuseBefore  = null;
        $host->refuseInGrant = new InvitationRefusal('host.seat_taken');
        $this->acceptThroughThePage($url);
        self::assertStringContainsString('host.seat_taken', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('seat@example.com'));

        $host->refuseInGrant = null;
        $this->acceptThroughThePage($url);
        self::assertResponseRedirects();
        self::assertNotNull($this->invitation('seat@example.com')->getAcceptedAt());
    }

    public function testExpiredAndRevokedLinksAreReportedWithoutTheToken(): void
    {
        $super   = $this->user('super@example.com');
        $revoked = (string) $this->manager()->invite($super, 'default', new InvitationRequest('gone@example.com', returnLink: true))->url;
        $expired = (string) $this->manager()->invite($super, 'default', new InvitationRequest('late@example.com', returnLink: true))->url;
        $this->manager()->revoke($super, $this->invitation('gone@example.com'));
        $this->entityManager()->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :past WHERE i.email = :email')
            ->setParameter('past', new DateTimeImmutable('-1 day'))->setParameter('email', 'late@example.com')->execute();

        $this->open($revoked);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="invalid"]');
        $this->client->request('GET', '/invitation');
        $this->open($expired);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="expired"]');
        $this->open('https://app.example.test/invitation/' . substr($this->token($revoked), 1) . 'A');

        $rejected = $this->hooks()->rejected;
        self::assertCount(2, $rejected, 'Once per opening; unknown links and reloads dispatch nothing.');
        self::assertSame([InvitationLinkRejectedEvent::REASON_REVOKED, InvitationLinkRejectedEvent::REASON_EXPIRED], [$rejected[0]->reason, $rejected[1]->reason]);
        self::assertSame('default', $rejected[0]->profile());
        self::assertSame($this->invitation('gone@example.com')->getPublicId(), $rejected[0]->invitationId());
        foreach ($rejected as $event) {
            self::assertStringNotContainsString($this->token($revoked), print_r($event, true));
            self::assertStringNotContainsString($this->token($expired), print_r($event, true));
        }
    }

    public function testResendTtlReturnedLinkAndRoleEscalationRecheck(): void
    {
        $super      = $this->user('super@example.com');
        $admin      = $this->user('admin@example.com');
        $first      = (string) $this->manager()->invite($super, 'default', new InvitationRequest('boss@example.com', ['ROLE_SUPER_ADMIN'], returnLink: true))->url;
        $invitation = $this->invitation('boss@example.com');
        $sent       = count($this->mailer()->sent);

        // SEC-004 CD M1: an actor lacking a role of the invitation gets no link, whatever the options.
        $refused = $this->manager()->resend($admin, $invitation, 14, true);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $refused->status);
        self::assertNull($refused->url);

        $resent = $this->manager()->resend($super, $invitation, 14, true);
        self::assertSame(InvitationOutcome::LINK, $resent->status);
        self::assertNotSame($first, $resent->url, 'A new link voids the previous one.');
        self::assertSame(14, $resent->invitation?->ttlDays);
        self::assertCount($sent, $this->mailer()->sent);
        $event = $this->listener()->events[1] ?? null;
        self::assertInstanceOf(InvitationResentEvent::class, $event);
        self::assertSame(InvitationCreatedEvent::DELIVERY_HOST, $event->delivery);

        // A validity that is not offered falls back to ttl_days; without returnLink the e-mail goes out.
        $mailed = $this->manager()->resend($super, $this->invitation('boss@example.com'), 999);
        self::assertSame(InvitationOutcome::SENT, $mailed->status);
        self::assertSame(7, $mailed->invitation?->ttlDays);
        self::assertNull($mailed->url);
    }

    public function testMergeKeyKeepsOneOpenInvitationPerOrganizationAndRechecksRoles(): void
    {
        $super = $this->user('super@example.com');
        $admin = $this->user('admin@example.com');
        $one   = $this->manager()->invite($super, 'default', new InvitationRequest('a@example.com', ['ROLE_EDITOR'], payload: ['organization' => 1, 'projects' => [1], 'meta' => ['a' => 1]]));
        $two   = $this->manager()->invite($super, 'default', new InvitationRequest('a@example.com', payload: ['organization' => 2]));
        $again = $this->manager()->invite($super, 'default', new InvitationRequest('a@example.com', payload: ['organization' => 1, 'projects' => [2, 1], 'meta' => ['b' => 2]]));

        self::assertNotSame($one->invitation?->publicId, $two->invitation?->publicId);
        self::assertSame($one->invitation?->publicId, $again->invitation?->publicId);
        self::assertSame(['organization' => 1, 'projects' => [1, 2], 'meta' => ['a' => 1, 'b' => 2]], $again->invitation?->payload);
        self::assertSame(['ROLE_EDITOR'], $again->invitation->roles);

        // SEC-004 CD M1 with merge_key: re-inviting the (e-mail, organization) of a higher-role invitation is refused;
        // another organization is a separate invitation that carries none of those roles.
        $this->manager()->invite($super, 'default', new InvitationRequest('b@example.com', ['ROLE_SUPER_ADMIN'], payload: ['organization' => 1]));
        $escalation = $this->manager()->invite($admin, 'default', new InvitationRequest('b@example.com', payload: ['organization' => 1, 'projects' => [9]], returnLink: true));
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $escalation->status);
        self::assertNull($escalation->url);
        $other = $this->manager()->invite($admin, 'default', new InvitationRequest('b@example.com', payload: ['organization' => 2], returnLink: true));
        self::assertSame(InvitationOutcome::LINK, $other->status);
        self::assertSame([], $other->invitation?->roles);
        $repository = self::getContainer()->get(InvitationRepository::class);
        self::assertInstanceOf(InvitationRepository::class, $repository);
        self::assertNull($repository->findOpenByEmailAndPayloadKey('default', 'b@example.com', 'organization', 3));
    }

    public function testMergeKeyAbsentIsNullAndNeverCollidesWithAnOrganization(): void
    {
        $super  = $this->user('super@example.com');
        $admin  = $this->user('admin@example.com');
        $invite = fn (array $payload, ?KernelUser $actor = null): ?string => $this->manager()->invite($actor ?? $super, 'default', new InvitationRequest('p@example.com', payload: $payload))->invitation?->publicId;

        // Org-less (platform / project) invitations merge with each other; an explicit null is the same as absent.
        $platform = $invite(['projects' => [1]]);
        self::assertSame($platform, $invite(['projects' => [2]]));
        self::assertSame($platform, $invite(['organization' => null]));
        self::assertSame(['projects' => [1, 2], 'organization' => null], $this->invitation('p@example.com')->getPayload());

        // An org-less invitation never merges with an organization one, nor org A with org B.
        // (Another actor: the per-actor-and-address send limit is 4 per window.)
        $orgA = $invite(['organization' => 'A'], $admin);
        $orgB = $invite(['organization' => 'B'], $admin);
        self::assertNotSame($platform, $orgA);
        self::assertNotSame($orgA, $orgB);
        self::assertNotSame($platform, $orgB);

        // Strict comparison: '' and 0 are values of their own, never null.
        $repository = self::getContainer()->get(InvitationRepository::class);
        self::assertInstanceOf(InvitationRepository::class, $repository);
        self::assertSame($platform, $repository->findOpenByEmailAndPayloadKey('default', 'p@example.com', 'organization', null)?->getPublicId());
        self::assertSame($orgA, $repository->findOpenByEmailAndPayloadKey('default', 'p@example.com', 'organization', 'A')?->getPublicId());
        self::assertNull($repository->findOpenByEmailAndPayloadKey('default', 'p@example.com', 'organization', ''));
        self::assertNull($repository->findOpenByEmailAndPayloadKey('default', 'p@example.com', 'organization', 0));
        self::assertNull($repository->findOpenByEmailAndPayloadKey('default', 'p@example.com', 'organization', false));
    }

    public function testSocialAcceptanceOfThePendingInvitation(): void
    {
        $acceptor = $this->acceptor();
        $session  = new Session(new MockArraySessionStorage());
        $url      = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('sam@example.com', ['ROLE_EDITOR'], returnLink: true))->url;
        $open     = fn () => $session->set(InvitationAcceptor::SESSION_KEY . 'default', $acceptor->hashToken($this->token($url)));

        self::assertNull($acceptor->pending($session, 'default'));
        self::assertSame(InvitationAcceptance::NO_INVITATION, $acceptor->acceptPendingForSocial($session, 'default', $this->social('sam@example.com', true))->status);

        $open();
        self::assertSame('sam@example.com', $acceptor->pending($session, 'default')?->email);
        self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptPendingForSocial($session, 'default', $this->social('sam@example.com', null))->status);
        self::assertNull($acceptor->pending($session, 'default'), 'The session entry is removed whatever the outcome.');
        $open();
        self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptPendingForSocial($session, 'default', $this->social('other@example.com', true))->status);

        $open();
        $acceptance = $acceptor->acceptPendingForSocial($session, 'default', $this->social('Sam@Example.com', true), ['nickname' => 'Sam', 'password' => 'from-the-host']);
        self::assertSame(InvitationAcceptance::ACCEPTED, $acceptance->status);
        self::assertTrue($acceptance->isAccepted());
        $user = $this->user('sam@example.com');
        self::assertSame('Sam', $user->getNickname());
        self::assertSame('', $user->getPassword(), 'Social acceptance never sets a password.');
        self::assertContains('ROLE_MEMBER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        $open();
        self::assertSame(InvitationAcceptance::NO_INVITATION, $acceptor->acceptPendingForSocial($session, 'default', $this->social('sam@example.com', true))->status);

        $late = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('late@example.com', returnLink: true))->url;
        $this->entityManager()->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :past WHERE i.email = :email')
            ->setParameter('past', new DateTimeImmutable('-1 day'))->setParameter('email', 'late@example.com')->execute();
        $this->entityManager()->clear();
        $session->set(InvitationAcceptor::SESSION_KEY . 'default', $acceptor->hashToken($this->token($late)));
        self::assertSame(InvitationAcceptance::EXPIRED, $acceptor->acceptPendingForSocial($session, 'default', $this->social('late@example.com', true))->status);

        // Pre-accept refusal reaches the social path too.
        $this->hooks()->refuseBefore = new InvitationRefusal('host.no_seat_left');
        $url2                        = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('zoe@example.com', returnLink: true))->url;
        $session->set(InvitationAcceptor::SESSION_KEY . 'default', $acceptor->hashToken($this->token($url2)));
        $refused = $acceptor->acceptPendingForSocial($session, 'default', $this->social('zoe@example.com', true));
        self::assertSame(InvitationAcceptance::REFUSED, $refused->status);
        self::assertSame('host.no_seat_left', $refused->refusal?->key);
    }

    public function testSocialAcceptanceFollowsTheInvitationGate(): void
    {
        $repository = self::getContainer()->get(InvitationRepository::class);
        $registrar  = self::getContainer()->get(UserRegistrar::class);
        $clock      = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(InvitationRepository::class, $repository);
        self::assertInstanceOf(UserRegistrar::class, $registrar);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $acceptor = new InvitationAcceptor($repository, ProfileRegistryFactory::single(TestUser::class), $registrar, $this->entityManager(), $clock);
        $session  = new Session(new MockArraySessionStorage());
        $session->set(InvitationAcceptor::SESSION_KEY . 'default', 'hash');

        self::assertSame(InvitationAcceptance::NO_INVITATION, $acceptor->acceptPendingForSocial($session, 'default', $this->social('a@example.com', true))->status);
        self::assertFalse($session->has(InvitationAcceptor::SESSION_KEY . 'default'));
    }

    public function testTheSocialStartIsOffByDefault(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNull($router->getRouteCollection()->get('nowo_auth_kit_invitation_social'));
        $controller = self::getContainer()->get(InvitationAcceptController::class);
        self::assertInstanceOf(InvitationAcceptController::class, $controller);
        $request = Request::create('/invitation/social/google', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');

        // Not a social callback handler unless invitations.social.accept_page is on.
        $request->getSession()->set(InvitationAcceptController::SOCIAL_SESSION_KEY, ['profile' => 'default', 'provider' => 'google', 'at' => time()]);
        self::assertFalse($controller->supports($request, 'google'));

        $this->expectException(NotFoundHttpException::class);
        $controller->social($request, 'google');
    }

    private function social(string $email, ?bool $verified): SocialUserProfile
    {
        return new SocialUserProfile('idp-1', $email, 'Sam', [], $verified);
    }

    private function token(string $url): string
    {
        return substr((string) parse_url($url, PHP_URL_PATH), strlen('/invitation/'));
    }

    private function open(string $url): void
    {
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));
        $this->client->followRedirect();
    }

    private function acceptThroughThePage(string $url): void
    {
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
    }

    private function manager(): InvitationManager
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);

        return $manager;
    }

    private function acceptor(): InvitationAcceptor
    {
        $acceptor = self::getContainer()->get(InvitationAcceptor::class);
        self::assertInstanceOf(InvitationAcceptor::class, $acceptor);

        return $acceptor;
    }

    private function invitation(string $email): Invitation
    {
        $em = $this->entityManager();
        $em->clear();
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => $email], ['id' => 'DESC']);
        self::assertInstanceOf(Invitation::class, $invitation);

        return $invitation;
    }

    private function user(string $email): KernelUser
    {
        $user = $this->entityManager()->getRepository(KernelUser::class)->find($email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
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

    private function hooks(): HostHookListener
    {
        $hooks = self::getContainer()->get(HostHookListener::class);
        self::assertInstanceOf(HostHookListener::class, $hooks);

        return $hooks;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
