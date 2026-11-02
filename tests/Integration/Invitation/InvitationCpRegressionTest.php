<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;

use function intdiv;
use function parse_url;
use function strlen;
use function substr;

use const PHP_URL_PATH;

/**
 * SEC-004 Appendix CP (1.33 on f23653e) PoCs turned into regression tests: L1 host authorisation / returned link /
 * last sender, L2 dead-link event throttle, I1 merge key normalisation and fail-closed scan, I2 social e-mail without
 * compatibility folding, I3 failing pre-accept listener refuses.
 */
final class InvitationCpRegressionTest extends WebTestCase
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
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $em->persist((new KernelUser())->setEmail('super@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
        $em->persist((new KernelUser())->setEmail('member@example.com')->setPassword('x')->setRoles(['ROLE_USER']));
        $em->flush();
        $em->clear();
    }

    /** CP L1 (PoC d/e): role-less invitations are guarded by the host authorizer; a returned link needs an actor. */
    public function testRolelessInvitationNeedsTheHostAuthorizerAndAnActorForTheLink(): void
    {
        $super = $this->user('super@example.com');
        $this->manager()->invite($super, 'default', new InvitationRequest('v@example.com', payload: ['organization' => 2, 'org_role' => 'member', 'teams' => ['a']]));
        $invitation = $this->invitation('v@example.com');

        // A returned link is never handed to a null actor, even for an invitation without roles.
        $anonymous = $this->manager()->resend(null, $invitation, 30, true);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $anonymous->status);
        self::assertNull($anonymous->url);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->invite(null, 'default', new InvitationRequest('n@example.com', returnLink: true))->status);

        // Host rule: only the super administrator may act on organization 2.
        $this->hooks()->asked     = [];
        $this->hooks()->authorize = static function (?UserInterface $actor, string $action, Invitation|InvitationRequest $subject): bool {
            $payload = $subject instanceof Invitation ? $subject->getPayload() : $subject->payload;

            return ($payload['organization'] ?? null) !== 2 || $actor?->getUserIdentifier() === 'super@example.com';
        };
        $member = $this->user('member@example.com');
        $merge  = $this->manager()->invite($member, 'default', new InvitationRequest('v@example.com', payload: ['organization' => 2, 'org_role' => 'owner', 'teams' => ['admins']], returnLink: true));
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $merge->status);
        self::assertNull($merge->url);
        self::assertSame(['organization' => 2, 'org_role' => 'member', 'teams' => ['a']], $this->invitation('v@example.com')->getPayload());
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->resend($member, $this->invitation('v@example.com'), null, true)->status);
        self::assertSame(InvitationOutcome::NOT_ALLOWED, $this->manager()->revoke($member, $this->invitation('v@example.com'))->status);
        self::assertSame([
            InvitationActionAuthorizerInterface::INVITE,
            InvitationActionAuthorizerInterface::RESEND,
            InvitationActionAuthorizerInterface::REVOKE,
        ], $this->hooks()->asked);

        // The re-invite by an allowed actor records who made it; the original inviter stays invitedBy.
        $this->hooks()->authorize = null;
        $this->hooks()->asked     = [];
        $allowed                  = $this->manager()->invite($member, 'default', new InvitationRequest('v@example.com', payload: ['organization' => 2], returnLink: true));
        self::assertSame(InvitationOutcome::LINK, $allowed->status);
        self::assertSame([InvitationActionAuthorizerInterface::INVITE, InvitationActionAuthorizerInterface::REINVITE, InvitationActionAuthorizerInterface::RETURN_LINK], $this->hooks()->asked);
        self::assertSame('super@example.com', $allowed->invitation?->invitedBy);
        self::assertSame('member@example.com', $allowed->invitation->lastSentBy);
        self::assertSame('member@example.com', $this->invitation('v@example.com')->getLastSentBy());
        $resent = $this->manager()->resend($super, $this->invitation('v@example.com'), null, true);
        self::assertSame(InvitationOutcome::LINK, $resent->status);
        self::assertSame('super@example.com', $resent->invitation?->lastSentBy);
    }

    /** CP L2 (PoC b): the dead-link event fires once per invitation and window, whatever the client IP. */
    public function testDeadLinkEventIsThrottledPerInvitation(): void
    {
        $url = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('late@example.com', returnLink: true))->url;
        $this->expire('late@example.com');
        $path = (string) parse_url($url, PHP_URL_PATH);
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', $path, [], [], ['REMOTE_ADDR' => '10.0.' . intdiv($i, 10) . '.' . $i]);
            $this->client->followRedirect();
            self::assertSelectorExists('[data-nowo-auth-kit-invitation="expired"]');
        }
        self::assertCount(1, $this->hooks()->rejected);
    }

    /** CP I1 (PoC e2/e3): 7 ≡ '7' (null stays distinct from '' and '0'); a full scan window refuses instead of forking. */
    public function testMergeKeyValuesAreNormalisedAndTheScanFailsClosed(): void
    {
        $super = $this->user('super@example.com');
        $a     = $this->manager()->invite($super, 'default', new InvitationRequest('w@example.com', payload: ['organization' => 7]));
        $b     = $this->manager()->invite($super, 'default', new InvitationRequest('w@example.com', payload: ['organization' => '7']));
        self::assertSame($a->invitation?->publicId, $b->invitation?->publicId);
        self::assertNull(InvitationRepository::mergeValue(null));
        self::assertSame('0', InvitationRepository::mergeValue(false));
        self::assertSame('1', InvitationRepository::mergeValue(true));
        self::assertNotSame(InvitationRepository::mergeValue(['7']), InvitationRepository::mergeValue('7'));

        try {
            $this->manager()->invite($super, 'default', new InvitationRequest('w@example.com', payload: ['organization' => ['x']]));
            self::fail('A non-scalar merge key value must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must be a scalar or null', $exception->getMessage());
        }

        $em     = $this->em();
        $tokens = new InvitationTokens();
        $now    = new DateTimeImmutable();
        $old    = (new Invitation('default', 'x@example.com', 'super@example.com', 7, $now))->setPayload(['organization' => 'X']);
        $old->issue($tokens->generate()[1], 7, $now);
        $em->persist($old);
        for ($i = 0; $i < InvitationRepository::MERGE_SCAN_LIMIT; ++$i) {
            $filler = (new Invitation('default', 'x@example.com', 'super@example.com', 7, $now))->setPayload(['organization' => 'o' . $i]);
            $filler->issue($tokens->generate()[1], 7, $now);
            $em->persist($filler);
        }
        $em->flush();
        $refused = $this->manager()->invite($super, 'default', new InvitationRequest('x@example.com', payload: ['organization' => 'X']));
        self::assertSame(InvitationOutcome::TOO_MANY_OPEN, $refused->status);
        $open = (int) $em->createQuery('SELECT COUNT(i) FROM ' . Invitation::class . " i WHERE i.email = 'x@example.com' AND i.acceptedAt IS NULL AND i.revokedAt IS NULL")->getSingleScalarResult();
        self::assertSame(InvitationRepository::MERGE_SCAN_LIMIT + 1, $open);
    }

    /** CP I2 (PoC f): no compatibility folding (KELVIN SIGN), ASCII case-insensitive, NFC byte match otherwise. */
    public function testSocialEmailMatchDoesNotFoldCompatibilityCharacters(): void
    {
        $acceptor = $this->acceptor();
        $session  = new Session(new MockArraySessionStorage());
        $this->pending($session, 'kate@example.com');
        self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptPendingForSocial($session, 'default', $this->social("\u{212A}ate@example.com"))->status);
        $this->pending($session, 'kate@example.com');
        self::assertSame(InvitationAcceptance::EMAIL_MISMATCH, $acceptor->acceptPendingForSocial($session, 'default', $this->social('k+x@example.com'))->status);
        $this->pending($session, 'kate@example.com');
        self::assertSame(InvitationAcceptance::ACCEPTED, $acceptor->acceptPendingForSocial($session, 'default', $this->social(' KATE@Example.com '))->status);

        // Non-ASCII: NFC, then byte for byte (decomposed é matches the composed invitation; another case does not).
        self::assertTrue(InvitationAcceptor::sameEmail("jose\u{0301}@example.com", "jos\u{00E9}@example.com"));
        self::assertFalse(InvitationAcceptor::sameEmail("JOS\u{00C9}@example.com", "jos\u{00E9}@example.com"));
    }

    /** CP I3 (PoC c): a throwing pre-accept listener refuses (no 500), nothing is created. */
    public function testThrowingAcceptingListenerRefuses(): void
    {
        $url        = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest('t@example.com', ['ROLE_EDITOR'], returnLink: true))->url;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(InvitationAcceptingEvent::class, static function (): never {
            throw new RuntimeException('listener down');
        });
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        self::assertNull($this->em()->getRepository(KernelUser::class)->find('t@example.com'));
        self::assertNull($this->invitation('t@example.com')->getAcceptedAt());
    }

    private function pending(Session $session, string $email): void
    {
        $url = (string) $this->manager()->invite($this->user('super@example.com'), 'default', new InvitationRequest($email, returnLink: true))->url;
        $session->set(InvitationAcceptor::SESSION_KEY . 'default', $this->acceptor()->hashToken(substr((string) parse_url($url, PHP_URL_PATH), strlen('/invitation/'))));
    }

    private function social(string $email): SocialUserProfile
    {
        return new SocialUserProfile('idp-1', $email, 'K', [], true);
    }

    private function expire(string $email): void
    {
        $this->em()->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :past WHERE i.email = :email')
            ->setParameter('past', new DateTimeImmutable('-1 day'))->setParameter('email', $email)->execute();
        $this->em()->clear();
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

    private function hooks(): HostHookListener
    {
        $hooks = self::getContainer()->get(HostHookListener::class);
        self::assertInstanceOf(HostHookListener::class, $hooks);

        return $hooks;
    }

    private function invitation(string $email): Invitation
    {
        $this->em()->clear();
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => $email], ['id' => 'DESC']);
        self::assertInstanceOf(Invitation::class, $invitation);

        return $invitation;
    }

    private function user(string $email): KernelUser
    {
        $user = $this->em()->getRepository(KernelUser::class)->find($email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
