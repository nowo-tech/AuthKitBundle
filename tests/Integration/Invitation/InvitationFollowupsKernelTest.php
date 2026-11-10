<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\SocialProviderResponses;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

use function array_column;
use function array_map;
use function count;
use function hash;
use function parse_str;
use function parse_url;
use function print_r;
use function strlen;
use function substr;
use function time;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * The 1.42 invitation follow-ups on a host-like kernel ({@see InvitationsFollowupsTestKernel}): an existing account
 * accepting through {@see InvitationAcceptor::acceptExisting()} (same checks, single use, races), bounded batches,
 * the configurable attempt-limiter pool, the opening pre-check (no consumption, no oracle without the token), the
 * invitation snapshot in the template, and the native social acceptance on the accept page (kept after a mismatch).
 */
final class InvitationFollowupsKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return InvitationsFollowupsTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        foreach (['cache.app', InvitationsFollowupsTestKernel::POOL] as $pool) {
            $cache = self::getContainer()->get($pool);
            self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
            $cache->clear();
        }
        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('admin@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN']));
        $em->persist((new KernelUser())->setEmail('super@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $em->persist((new KernelUser())->setEmail('member@example.com')->setPassword('x')->setRoles(['ROLE_USER']));
        $em->persist((new SocialLoginCredential())->setProvider('google')->setLabel('Google')->setEnabled(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder'));
        $em->persist((new SocialLoginCredential())->setProvider('corp')->setLabel('Corp SSO')->setEnabled(true)->setEnterpriseSso(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder')
            ->setAuthorizeUrl('https://sso.example.test/authorize')->setTokenUrl('https://sso.example.test/token')->setUserinfoUrl('https://sso.example.test/userinfo'));
        $em->flush();
        $em->clear();
    }

    // ---- 1. acceptExisting() --------------------------------------------------------------------------------------

    public function testAnExistingAccountAcceptsWithTheEventAndNoNewAccount(): void
    {
        $this->invite('member@example.com', ['organization' => 'acme']);
        $member     = $this->user('member@example.com');
        $acceptance = $this->acceptor()->acceptExisting($member, $this->invitation('member@example.com'), 'Member@Example.com');

        self::assertSame(InvitationAcceptance::ACCEPTED, $acceptance->status);
        self::assertSame($member, $acceptance->user);
        self::assertSame('member@example.com', $acceptance->invitation?->acceptedBy);
        $stored = $this->invitation('member@example.com');
        self::assertNotNull($stored->getAcceptedAt());
        self::assertSame('member@example.com', $stored->getAcceptedBy());
        self::assertSame(['member@example.com'], $this->hooks()->acceptingUsers, 'The pre-accept event names the existing account.');
        self::assertSame([], $this->hooks()->registered, 'No account was created, so no UserRegisteredEvent.');
        self::assertSame(['member@example.com -> member@example.com'], $this->listener()->granted, 'The host grants run.');

        $accepted = $this->acceptedEvents();
        self::assertCount(1, $accepted);
        self::assertTrue($accepted[0]->existingAccount);
        self::assertSame('member@example.com', $accepted[0]->userIdentifier);
        self::assertSame(KernelUser::class, $accepted[0]->userClass);
        self::assertSame('default', $accepted[0]->profile());
        self::assertSame($stored->getPublicId(), $accepted[0]->invitationId());
        self::assertStringNotContainsString($stored->getTokenHash(), print_r($accepted[0]->invitation, true));
        self::assertSame(1, count($this->entityManager()->getRepository(KernelUser::class)->findBy(['email' => 'member@example.com'])));

        // Single use: the accepted invitation cannot be accepted again, by any account.
        self::assertSame(InvitationAcceptance::NO_INVITATION, $this->acceptor()->acceptExisting($member, $this->invitation('member@example.com'), 'member@example.com')->status);
        self::assertCount(1, $this->acceptedEvents());
    }

    public function testAcceptExistingRunsTheSameChecksAsTheAcceptPage(): void
    {
        $acceptor = $this->acceptor();
        $member   = $this->user('member@example.com');

        // E-mail match policy (SEC-004 CP I2): another address, a Unicode look-alike, an empty value.
        $this->invite('member@example.com');
        foreach (['other@example.com', "member@exa\u{212A}ple.com", '', 'member@example.com.evil'] as $email) {
            self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptExisting($member, $this->invitation('member@example.com'), $email)->status, $email);
        }
        self::assertNull($this->invitation('member@example.com')->getAcceptedAt(), 'A mismatch keeps the invitation pending.');

        // Roles are never added to an existing account: such an invitation is refused and stays pending.
        $this->invite('roles@example.com', [], ['ROLE_EDITOR']);
        $roles = $acceptor->acceptExisting($member, $this->invitation('roles@example.com'), 'roles@example.com');
        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $roles->status);
        self::assertNull($this->invitation('roles@example.com')->getAcceptedAt());

        // Expired (reported as such), revoked (neutral).
        $this->invite('late@example.com');
        $this->expire('late@example.com');
        self::assertSame(InvitationAcceptance::EXPIRED, $acceptor->acceptExisting($member, $this->invitation('late@example.com'), 'late@example.com')->status);
        $this->invite('gone@example.com');
        $this->manager()->revoke($this->user('super@example.com'), $this->invitation('gone@example.com'));
        self::assertSame(InvitationAcceptance::NO_INVITATION, $acceptor->acceptExisting($member, $this->invitation('gone@example.com'), 'gone@example.com')->status);
        self::assertSame([], $this->acceptedEvents());
    }

    public function testAcceptExistingIsRaceSafe(): void
    {
        $member = $this->user('member@example.com');
        $other  = $this->user('admin@example.com');
        $this->invite('member@example.com');

        // Two requests loaded the same pending invitation; the first claim wins, the stale copy cannot claim it.
        $first = $this->invitation('member@example.com');
        $this->entityManager()->clear();
        $stale = $this->invitation('member@example.com');
        self::assertSame(InvitationAcceptance::ACCEPTED, $this->acceptor()->acceptExisting($this->user('member@example.com'), $first, 'member@example.com')->status);
        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $this->acceptor()->acceptExisting($other, $stale, 'member@example.com')->status);
        self::assertSame('member@example.com', $this->invitation('member@example.com')->getAcceptedBy());

        // A resend (new link) between loading and accepting voids the loaded copy: it is no longer the current link.
        $this->invite('next@example.com');
        $loaded = $this->invitation('next@example.com');
        $this->entityManager()->clear();
        self::assertSame(InvitationOutcome::SENT, $this->manager()->resend($this->user('super@example.com'), $this->invitation('next@example.com'))->status);
        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $this->acceptor()->acceptExisting($member, $loaded, 'next@example.com')->status);
        self::assertNull($this->invitation('next@example.com')->getAcceptedAt());
        self::assertCount(1, $this->acceptedEvents());
    }

    public function testAcceptExistingHonoursTheHostRefusalsAndKeepsNothing(): void
    {
        $member = $this->user('member@example.com');
        $hooks  = $this->hooks();
        $this->invite('member@example.com', ['organization' => 'acme']);

        $hooks->refuseBefore = new InvitationRefusal('host.no_seat_left', ['%org%' => 'Acme']);
        $seat                = $this->acceptor()->acceptExisting($member, $this->invitation('member@example.com'), 'member@example.com');
        self::assertSame(InvitationAcceptance::REFUSED, $seat->status);
        self::assertSame('host.no_seat_left', $seat->refusal?->key);

        $hooks->refuseBefore = null;
        $hooks->crashBefore  = true;
        self::assertSame(InvitationAcceptance::REFUSED, $this->acceptor()->acceptExisting($member, $this->invitation('member@example.com'), 'member@example.com')->status, 'A failing listener refuses (CP I3).');

        $hooks->crashBefore   = false;
        $hooks->refuseInGrant = new InvitationRefusal('host.seat_taken');
        $grant                = $this->acceptor()->acceptExisting($member, $this->invitation('member@example.com'), 'member@example.com');
        self::assertSame(InvitationAcceptance::REFUSED, $grant->status);
        self::assertSame('host.seat_taken', $grant->refusal?->key);
        self::assertNull($this->invitation('member@example.com')->getAcceptedAt(), 'The claim was rolled back with the refused grant.');
        self::assertSame([], $this->acceptedEvents());

        $hooks->refuseInGrant = null;
        self::assertSame(InvitationAcceptance::ACCEPTED, $this->acceptor()->acceptExisting($this->user('member@example.com'), $this->invitation('member@example.com'), 'member@example.com')->status);
    }

    public function testAcceptExistingFollowsTheInvitationGate(): void
    {
        $this->invite('member@example.com');
        $acceptor = new InvitationAcceptor(
            $this->service(InvitationRepository::class),
            ProfileRegistryFactory::single(TestUser::class),
            $this->service(UserRegistrar::class),
            $this->entityManager(),
            $this->service(ClockInterface::class),
        );

        self::assertSame(InvitationAcceptance::NO_INVITATION, $acceptor->acceptExisting($this->user('member@example.com'), $this->invitation('member@example.com'), 'member@example.com')->status);
        self::assertNull($this->invitation('member@example.com')->getAcceptedAt());
        self::assertNull($acceptor->precheck($this->invitation('member@example.com')), 'Without an event dispatcher nothing refuses.');
    }

    // ---- 2. inviteBatch() -----------------------------------------------------------------------------------------

    public function testABatchCountsAsOneSendOfTheInviterAndIsBounded(): void
    {
        $admin  = $this->user('admin@example.com');
        $mailer = $this->mailer();

        // invite_rate_limit: 10 single invitations per inviter and window.
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(InvitationOutcome::SENT, $this->manager()->invite($admin, 'default', new InvitationRequest('single' . $i . '@example.com'))->status);
        }
        self::assertSame(InvitationOutcome::RATE_LIMITED, $this->manager()->invite($admin, 'default', new InvitationRequest('eleven@example.com'))->status);

        // A batch takes one slot of batch.rate_limit instead: three invitations go out, the role check still applies.
        $sent     = count($mailer->sent);
        $outcomes = $this->manager()->inviteBatch($admin, 'default', [
            new InvitationRequest('a@example.com', payload: ['organization' => 'acme']),
            new InvitationRequest('B@Example.com', payload: ['organization' => 'acme']),
            new InvitationRequest('c@example.com', ['ROLE_SUPER_ADMIN']),
        ]);
        self::assertSame([InvitationOutcome::SENT, InvitationOutcome::SENT, InvitationOutcome::NOT_ALLOWED], array_column($outcomes, 'status'));
        self::assertSame('b@example.com', $outcomes[1]->invitation?->email);
        self::assertCount($sent + 2, $mailer->sent);
        self::assertSame(InvitationOutcome::RATE_LIMITED, $this->manager()->invite($admin, 'default', new InvitationRequest('four@example.com'))->status, 'The batch did not reset the inviter limit.');

        // batch.rate_limit: 1 batch per window; the refused batch sends nothing.
        $refused = $this->manager()->inviteBatch($admin, 'default', [new InvitationRequest('d@example.com')]);
        self::assertSame([InvitationOutcome::RATE_LIMITED], array_column($refused, 'status'));
        self::assertNotNull($refused[0]->retryAfter);
        self::assertCount($sent + 2, $mailer->sent);
        self::assertSame([], $this->manager()->inviteBatch($admin, 'default', []));

        // Another inviter has its own batch slot; the per-address limit still applies inside a batch (4 sends per actor and address).
        $super    = $this->user('super@example.com');
        $outcomes = $this->manager()->inviteBatch($super, 'default', [
            new InvitationRequest('same@example.com'),
            new InvitationRequest('same@example.com'),
            new InvitationRequest('same@example.com'),
        ]);
        self::assertSame([InvitationOutcome::SENT, InvitationOutcome::SENT, InvitationOutcome::SENT], array_column($outcomes, 'status'));
        self::assertSame(1, count($this->entityManager()->getRepository(Invitation::class)->findBy(['email' => 'same@example.com'])), 'Re-invites in a batch merge into the open invitation.');
    }

    public function testABatchIsValidatedBeforeAnythingIsSent(): void
    {
        $admin  = $this->user('admin@example.com');
        $sent   = count($this->mailer()->sent);
        $checks = [
            'too large'       => [array_map(static fn (int $i): InvitationRequest => new InvitationRequest('n' . $i . '@example.com'), [1, 2, 3, 4]), 'at most 3 requests'],
            'invalid e-mail'  => [[new InvitationRequest('ok@example.com'), new InvitationRequest('not-an-email')], 'Invalid invitation e-mail.'],
            'forged role'     => [[new InvitationRequest('ok@example.com'), new InvitationRequest('x@example.com', ['ROLE_ROOT'])], 'Roles not grantable'],
            'merge key value' => [[new InvitationRequest('ok@example.com'), new InvitationRequest('y@example.com', payload: ['organization' => true])], 'must be a string, an integer or null'],
        ];
        foreach ($checks as $name => [$requests, $message]) {
            try {
                $this->manager()->inviteBatch($admin, 'default', $requests);
                self::fail($name . ' must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
        self::assertCount($sent, $this->mailer()->sent);
        self::assertSame([], $this->entityManager()->getRepository(Invitation::class)->findAll());
        // Nothing was consumed either: the batch slot is still free.
        self::assertSame([InvitationOutcome::SENT], array_column($this->manager()->inviteBatch($admin, 'default', [new InvitationRequest('ok@example.com')]), 'status'));
    }

    // ---- 3. attempt_limiter.cache_pool ----------------------------------------------------------------------------

    public function testTheAttemptCountersLiveInTheConfiguredPool(): void
    {
        $limiter = $this->service(AuthKitAttemptLimiter::class);
        self::assertTrue($limiter->consume('followups-probe', 1, 60));
        self::assertFalse($limiter->consume('followups-probe', 1, 60));

        $pool = self::getContainer()->get(InvitationsFollowupsTestKernel::POOL);
        $app  = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        self::assertInstanceOf(CacheItemPoolInterface::class, $app);
        $key = 'auth_kit_limit_' . hash('sha256', 'followups-probe');
        self::assertTrue($pool->hasItem($key));
        self::assertFalse($app->hasItem($key), 'cache.app is no longer used for the counters.');
    }

    // ---- 5 + 6. The accept page: snapshot, pre-check --------------------------------------------------------------

    public function testTheAcceptPageReceivesTheSnapshotAndTheProviders(): void
    {
        $url     = $this->inviteLink('page@example.com', ['organization' => 'acme']);
        $crawler = $this->open($url);

        self::assertResponseIsSuccessful();
        $invitation = $this->invitation('page@example.com');
        self::assertSame('page@example.com|' . $invitation->getPublicId() . '|pending|acme', $crawler->filter('[data-testid="followups-invitation"]')->text());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->token($url), $content);
        self::assertStringNotContainsString($invitation->getTokenHash(), $content);
        self::assertCount(1, $crawler->filter('[data-testid="auth-kit-invitation-social-google"]'));
        self::assertCount(0, $crawler->filter('[data-testid="auth-kit-invitation-social-corp"]'), 'Enterprise SSO providers are not offered.');
        self::assertStringContainsString('Continue with Google', $content);
        self::assertSame('/invitation/social/google', $crawler->filter('[data-testid="auth-kit-invitation-social-google"]')->form()->getFormNode()->getAttribute('action'));
    }

    public function testAHostRefusalWhenTheLinkOpensConsumesNothing(): void
    {
        $hooks = $this->hooks();
        $url   = $this->inviteLink('seat@example.com', ['organization' => 'acme']);

        $hooks->refuseOpening = new InvitationRefusal('host.no_seat_left', ['%org%' => 'Acme']);
        $this->open($url);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="unavailable"]');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('host.no_seat_left', $content);
        self::assertStringNotContainsString('host.second_reason_ignored', $content, 'The first reason is kept.');
        self::assertStringNotContainsString('seat@example.com', $content, 'No invitee data on the notice.');
        self::assertSelectorNotExists('form[name="registration_form"]');

        // Reloading the token-less page checks again (the session entry stays); the neutral text without a reason.
        $hooks->refuseOpening              = null;
        $hooks->refuseOpeningWithoutReason = true;
        $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="unavailable"]');
        self::assertStringContainsString('This invitation cannot be accepted right now', (string) $this->client->getResponse()->getContent());

        $hooks->refuseOpeningWithoutReason = false;
        $hooks->crashOpening               = true;
        $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="unavailable"]', 'A failing listener refuses.');

        // Once the host allows it, the same link (never consumed) shows the form and the acceptance works.
        $hooks->crashOpening = false;
        $crawler             = $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-testid="auth-kit-invitation_accept"] form');
        self::assertNull($this->invitation('seat@example.com')->getAcceptedAt());
        $openings = $hooks->openings;
        $this->submit($crawler);
        self::assertResponseRedirects();
        self::assertSame($openings, $hooks->openings, 'The pre-check runs on GET only; the submit has the pre-accept event.');
        self::assertNotNull($this->invitation('seat@example.com')->getAcceptedAt());
    }

    public function testThePreCheckIsNeverReachedWithoutTheCurrentToken(): void
    {
        $hooks                = $this->hooks();
        $hooks->refuseOpening = new InvitationRefusal('host.no_seat_left');
        $revoked              = $this->inviteLink('gone@example.com');
        $replaced             = $this->inviteLink('again@example.com');
        $expired              = $this->inviteLink('late@example.com');
        $this->manager()->revoke($this->user('super@example.com'), $this->invitation('gone@example.com'));
        $this->manager()->resend($this->user('super@example.com'), $this->invitation('again@example.com'), null, true);
        $this->expire('late@example.com');

        $unknown = 'https://app.example.test/invitation/' . substr($this->token($revoked), 1) . 'A';
        foreach ([$unknown => 'invalid', $revoked => 'invalid', $replaced => 'invalid', $expired => 'expired'] as $link => $kind) {
            $this->open($link);
            self::assertSelectorExists('[data-nowo-auth-kit-invitation="' . $kind . '"]');
            self::assertStringNotContainsString('host.no_seat_left', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame(0, $hooks->openings, 'Unknown, revoked, replaced and expired links never reach the host (no oracle).');
    }

    // ---- 4 + 5. Social acceptance on the accept page --------------------------------------------------------------

    public function testSocialAcceptanceFromTheAcceptPage(): void
    {
        $url = $this->inviteLink('new.person@example.com', ['organization' => 'acme'], ['ROLE_EDITOR']);
        $this->open($url);
        $this->startSocial();
        self::assertResponseRedirects('/login/social/google', 303);
        $state = $this->authorize();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertResponseRedirects('/home');
        $user = $this->user('new.person@example.com');
        self::assertSame('New Person', $user->getNickname(), 'invitations.social.name_field');
        self::assertContains('ROLE_EDITOR', $user->getRoles());
        self::assertContains('ROLE_MEMBER', $user->getRoles());
        self::assertNotNull($this->invitation('new.person@example.com')->getAcceptedAt());
        $link = $this->entityManager()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'google', 'providerUserId' => 'subject-1']);
        self::assertInstanceOf(SocialLoginAccount::class, $link, 'The provider identity is linked to the new account.');
        $token = $this->service(TokenStorageInterface::class)->getToken();
        self::assertSame('new.person@example.com', $token?->getUserIdentifier());
        $accepted = $this->acceptedEvents();
        self::assertCount(1, $accepted);
        self::assertFalse($accepted[0]->existingAccount);
        self::assertCount(1, $this->hooks()->registered);
    }

    public function testASocialMismatchKeepsTheInvitationPending(): void
    {
        $this->provider()->email = 'someone.else@example.com';
        $url                     = $this->inviteLink('new.person@example.com');
        $this->open($url);
        $this->startSocial();
        $state = $this->authorize();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="social_mismatch"]');
        self::assertStringContainsString('Back to the invitation', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->service(TokenStorageInterface::class)->getToken());
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('someone.else@example.com'));
        self::assertNull($this->invitation('new.person@example.com')->getAcceptedAt());

        // keep_pending_on_mismatch: the invitee retries without opening the link again — with the form this time.
        $crawler = $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-testid="auth-kit-invitation_accept"] form');
        $this->submit($crawler);
        self::assertResponseRedirects();
        self::assertNotNull($this->invitation('new.person@example.com')->getAcceptedAt());
    }

    public function testAProviderFailureLeavesTheInvitationOpen(): void
    {
        $this->provider()->failTokenExchange = true;
        $this->open($this->inviteLink('new.person@example.com'));
        $this->startSocial();
        $state = $this->authorize();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="social_failed"]');
        self::assertStringContainsString('Back to the invitation', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-testid="auth-kit-invitation_accept"] form');
        self::assertNull($this->invitation('new.person@example.com')->getAcceptedAt());
    }

    public function testTheSocialStartIsBoundToTheOpenInvitation(): void
    {
        // Nothing opened: back to the accept page (its neutral notice).
        $this->client->request('POST', '/invitation/social/google');
        self::assertResponseRedirects('/invitation', 303);

        $crawler = $this->open($this->inviteLink('new.person@example.com'));
        $form    = $crawler->filter('[data-testid="auth-kit-invitation-social-google"]')->form();

        // CSRF bound to the invitation: missing or forged token, or another invitation's form, fails closed.
        $this->client->request('POST', '/invitation/social/google', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/invitation/social/google');
        self::assertResponseStatusCodeSame(403);

        // An unknown or SSO provider: back to the page; nothing remembered.
        $values = $form->getPhpValues();
        $this->client->request('POST', '/invitation/social/nope', $values);
        self::assertResponseRedirects('/invitation', 303);
        $this->client->request('POST', '/invitation/social/corp', $values);
        self::assertResponseRedirects('/invitation', 303);

        // A host refusal at the start: the unavailable page, no OAuth round trip.
        $this->hooks()->refuseOpening = new InvitationRefusal('host.no_seat_left');
        $this->client->request('POST', '/invitation/social/google', $values);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="unavailable"]');
        $this->hooks()->refuseOpening = null;

        // Signed in: refused (no account merge).
        $this->client->loginUser($this->user('admin@example.com'));
        $this->client->request('POST', '/invitation/social/google', $values);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="signed_in"]');
    }

    public function testTheSocialStartIsRateLimited(): void
    {
        $this->open($this->inviteLink('new.person@example.com'));
        for ($i = 0; $i < 19; ++$i) {
            $this->client->request('POST', '/invitation/social/google');
        }
        $this->client->request('POST', '/invitation/social/google');
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheCallbackHandlerOnlyOwnsItsOwnRecentStart(): void
    {
        $controller = $this->service(InvitationAcceptController::class);
        $request    = Request::create('/login/social/google/check');
        self::assertFalse($controller->supports($request, 'google'), 'No session.');

        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $session = $request->getSession();
        self::assertFalse($controller->supports($request, 'google'), 'Nothing started.');
        $now = time();
        foreach ([
            'another provider'  => [['profile' => 'default', 'provider' => 'github', 'at' => $now], false],
            'another profile'   => [['profile' => 'admin', 'provider' => 'google', 'at' => $now], false],
            'abandoned'         => [['profile' => 'default', 'provider' => 'google', 'at' => $now - InvitationAcceptController::SOCIAL_PENDING_SECONDS - 5], false],
            'not a timestamp'   => [['profile' => 'default', 'provider' => 'google', 'at' => (string) $now], false],
            'not a start'       => ['google', false],
            'its own, recently' => [['profile' => 'default', 'provider' => 'google', 'at' => $now], true],
        ] as $name => [$marker, $expected]) {
            $session->set(InvitationAcceptController::SOCIAL_SESSION_KEY, $marker);
            self::assertSame($expected, $controller->supports($request, 'google'), $name);
        }
    }

    public function testTheCallbackAnswersEveryOutcomeWithoutLeakingTheInvitee(): void
    {
        // A host refusal on the acceptance shows its reason.
        $this->hooks()->refuseBefore = new InvitationRefusal('host.no_seat_left');
        $this->provider()->email     = 'refused.person@example.com';
        $this->open($this->inviteLink('refused.person@example.com'));
        $this->startSocial();
        $state = $this->authorize();
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        self::assertStringContainsString('host.no_seat_left', (string) $this->client->getResponse()->getContent());
        $this->hooks()->refuseBefore = null;

        // The invitation expired or was revoked between the start and the callback.
        $changes = [
            'expired' => ['late.person@example.com', fn () => $this->expire('late.person@example.com')],
            'invalid' => ['gone.person@example.com', fn (): InvitationOutcome => $this->manager()->revoke($this->user('super@example.com'), $this->invitation('gone.person@example.com'))],
        ];
        foreach ($changes as $kind => [$email, $change]) {
            $this->provider()->email = $email;
            $this->open($this->inviteLink($email));
            $this->startSocial();
            $state = $this->authorize();
            $change();
            $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
            self::assertSelectorExists('[data-nowo-auth-kit-invitation="' . $kind . '"]');
            self::assertStringNotContainsString($email, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame([], $this->acceptedEvents());
    }

    public function testTheCallbackRefusesAPersonSignedInMeanwhile(): void
    {
        $url     = $this->inviteLink('new.person@example.com');
        $request = Request::create('/login/social/google/check');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $request->getSession()->set(InvitationAcceptor::SESSION_KEY . 'default', $this->acceptor()->hashToken($this->token($url)));
        $request->getSession()->set(InvitationAcceptController::SOCIAL_SESSION_KEY, ['profile' => 'default', 'provider' => 'google', 'at' => time()]);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($this->user('admin@example.com'), 'main', ['ROLE_ADMIN']));
        $profile = $this->service(ProfileRegistry::class)->getByName('default');
        $context = new SocialCallbackContext($profile, 'google', new SocialUserProfile('subject-1', 'new.person@example.com', 'New Person', [], true), ['access_token' => 'at', 'refresh_token' => null, 'expires_in' => null, 'raw' => []]);

        $response = $this->service(InvitationAcceptController::class)->handle($request, $context);
        self::assertStringContainsString('data-nowo-auth-kit-invitation="signed_in"', (string) $response->getContent());
        self::assertFalse($request->getSession()->has(InvitationAcceptController::SOCIAL_SESSION_KEY), 'The start is used up.');
        self::assertTrue($request->getSession()->has(InvitationAcceptor::SESSION_KEY . 'default'), 'The invitation stays open.');
        self::assertNull($this->invitation('new.person@example.com')->getAcceptedAt());
    }

    public function testTheCallbackConsumesNothingWhenTheSignInCannotHappen(): void
    {
        $url     = $this->inviteLink('new.person@example.com');
        $request = Request::create('/login/social/google/check');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $request->getSession()->set(InvitationAcceptor::SESSION_KEY . 'default', $this->acceptor()->hashToken($this->token($url)));
        // GY-L9: two host authenticators and neither form_login nor login_link: Security::login() would refuse.
        $factories  = ['security.authenticator.custom_a.main' => static fn (): null => null, 'security.authenticator.custom_b.main' => static fn (): null => null];
        $controller = new InvitationAcceptController(
            twig: $this->service(Environment::class),
            formFactory: $this->service(FormFactoryInterface::class),
            profileResolver: $this->service(RequestProfileResolver::class),
            registrationGate: $this->service(RegistrationGate::class),
            acceptor: $this->acceptor(),
            attemptLimiter: $this->service(AuthKitAttemptLimiter::class),
            tokenStorage: $this->service(TokenStorageInterface::class),
            urlGenerator: $this->service(AuthKitUrlGenerator::class),
            security: $this->service(Security::class),
            loginAuthenticator: new ProgrammaticLoginAuthenticator(['main' => new ServiceLocator($factories)]),
        );
        $profile = $this->service(ProfileRegistry::class)->getByName('default');
        $context = new SocialCallbackContext($profile, 'google', new SocialUserProfile('subject-1', 'new.person@example.com', 'New Person', [], true), ['access_token' => 'at', 'refresh_token' => null, 'expires_in' => null, 'raw' => []]);

        self::assertStringContainsString('data-nowo-auth-kit-invitation="not_accepted"', (string) $controller->handle($request, $context)->getContent());
        self::assertTrue($request->getSession()->has(InvitationAcceptor::SESSION_KEY . 'default'), 'Nothing was tried: the invitation stays open.');
        self::assertNull($this->invitation('new.person@example.com')->getAcceptedAt());
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('new.person@example.com'));
    }

    public function testTheAcceptorKeepsThePendingInvitationAfterAMismatchOnly(): void
    {
        $acceptor = $this->acceptor();
        $session  = new Session(new MockArraySessionStorage());
        $url      = $this->inviteLink('sam@example.com');
        $session->set(InvitationAcceptor::SESSION_KEY . 'default', $acceptor->hashToken($this->token($url)));

        foreach ([['sam@example.com', null], ['other@example.com', true], ['sam@example.com', false]] as [$email, $verified]) {
            self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptPendingForSocial($session, 'default', new SocialUserProfile('idp-1', $email, 'Sam', [], $verified))->status);
            self::assertSame('sam@example.com', $acceptor->pending($session, 'default')?->email, 'Still pending in the session after a mismatch.');
        }
        self::assertNull($this->invitation('sam@example.com')->getAcceptedAt(), 'The invitation itself is never touched.');

        $this->hooks()->refuseBefore = new InvitationRefusal('host.no_seat_left');
        self::assertSame(InvitationAcceptance::REFUSED, $acceptor->acceptPendingForSocial($session, 'default', new SocialUserProfile('idp-1', 'sam@example.com', 'Sam', [], true))->status);
        self::assertNull($acceptor->pending($session, 'default'), 'Any other outcome forgets it (1.33).');
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $roles
     */
    private function invite(string $email, array $payload = [], array $roles = []): InvitationOutcome
    {
        return $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest($email, $roles, payload: $payload, returnLink: true));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $roles
     */
    private function inviteLink(string $email, array $payload = [], array $roles = []): string
    {
        $outcome = $this->invite($email, $payload, $roles);
        self::assertSame(InvitationOutcome::LINK, $outcome->status);

        return (string) $outcome->url;
    }

    private function expire(string $email): void
    {
        $this->entityManager()->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :past WHERE i.email = :email')
            ->setParameter('past', new DateTimeImmutable('-1 day'))->setParameter('email', $email)->execute();
        $this->entityManager()->clear();
    }

    private function open(string $url): Crawler
    {
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));

        return $this->client->followRedirect();
    }

    private function submit(Crawler $crawler): void
    {
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
    }

    /** Submits the Google form of the accept page currently open. */
    private function startSocial(): void
    {
        $crawler = $this->client->request('GET', '/invitation');
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation-social-google"]')->form());
    }

    /** Follows the social start to the provider; returns the OAuth state. */
    private function authorize(): string
    {
        $this->client->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    private function token(string $url): string
    {
        return substr((string) parse_url($url, PHP_URL_PATH), strlen('/invitation/'));
    }

    /**
     * @return list<InvitationAcceptedEvent>
     */
    private function acceptedEvents(): array
    {
        $events = [];
        foreach ($this->listener()->events as $event) {
            if ($event instanceof InvitationAcceptedEvent) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function manager(): InvitationManager
    {
        return $this->service(InvitationManager::class);
    }

    private function acceptor(): InvitationAcceptor
    {
        return $this->service(InvitationAcceptor::class);
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
        return $this->service(CapturingInvitationMailer::class);
    }

    private function listener(): CapturingInvitationListener
    {
        return $this->service(CapturingInvitationListener::class);
    }

    private function hooks(): HostHookListener
    {
        return $this->service(HostHookListener::class);
    }

    private function provider(): SocialProviderResponses
    {
        return $this->service(SocialProviderResponses::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        /** @var T $service */
        $service = self::getContainer()->get($id);

        return $service;
    }
}
