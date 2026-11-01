<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationsPurgedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function array_map;
use function parse_url;
use function str_repeat;
use function strlen;

use const PHP_URL_PATH;

/**
 * Invitations through the real kernel: administration (access, invite, forged roles, resend, revoke, link shown once,
 * CSRF bound to the action and the row), the accept page on the registration form (token moved to the session, e-mail
 * locked, single use, expiry, neutral answers, rate limit, signed-in visitors), grants and audit events.
 */
final class InvitationFlowKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return InvitationsTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        // Rate limit counters live in cache.app (kernel cache dir): every test starts with fresh ones.
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $em->persist((new KernelUser())->setEmail('admin@example.com')->setPassword('admin-password-123')->setRoles(['ROLE_ADMIN']));
        $em->persist((new KernelUser())->setEmail('member@example.com')->setPassword('member-password-123'));
        $em->flush();
        $em->clear();
    }

    public function testAnonymousVisitorsAreSentToTheSignInPage(): void
    {
        $this->client->request('GET', '/admin/invitations');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testUsersWithoutTheAccessRoleAreRefused(): void
    {
        $this->client->loginUser($this->user('member@example.com'));
        $this->client->request('GET', '/admin/invitations');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/invitations/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInviteAcceptAndSingleUse(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $crawler = $this->client->request('GET', '/admin/invitations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('[data-testid="nowo-auth-kit-invitations"]');

        $crawler = $this->client->request('GET', '/admin/invitations/new');
        self::assertResponseIsSuccessful();
        // Only the grantable roles the administrator holds (ROLE_EDITOR through the hierarchy, not ROLE_SUPER_ADMIN).
        self::assertSame(['ROLE_EDITOR'], $crawler->filter('input[name="invitation[roles][]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        $form = $crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form([
            'invitation[email]'   => ' Ana@Example.com ',
            'invitation[ttlDays]' => '3',
            'invitation[message]' => 'Welcome <b>aboard</b>',
        ]);
        /** @var list<ChoiceFormField> $roles */
        $roles = $form['invitation[roles]'];
        $roles[0]->tick();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/admin/invitations', $this->client->getResponse()->headers->get('Location'));

        $mail = $this->mailer()->last();
        self::assertNotNull($mail);
        self::assertSame('ana@example.com', $mail->invitation->email);
        self::assertSame(['ROLE_EDITOR'], $mail->invitation->roles);
        self::assertSame(3, $mail->invitation->ttlDays);
        self::assertSame('admin@example.com', $mail->invitation->invitedBy);
        self::assertStringStartsWith('https://app.example.test/invitation/', $mail->acceptUrl);
        $token = substr((string) parse_url($mail->acceptUrl, PHP_URL_PATH), strlen('/invitation/'));

        $stored = $this->invitation('ana@example.com');
        self::assertSame(hash('sha256', $token), $stored->getTokenHash(), 'Only the hash of the token is stored.');
        self::assertNotSame($token, $stored->getTokenHash());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="nowo-auth-kit-invitation-email"]', 'ana@example.com');
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($stored->getTokenHash(), (string) $this->client->getResponse()->getContent());

        $created = $this->listener()->events[0] ?? null;
        self::assertInstanceOf(InvitationCreatedEvent::class, $created);
        self::assertSame(InvitationCreatedEvent::DELIVERY_EMAIL, $created->delivery);

        // Accept: the link moves the token into the session and redirects to the token-less URL.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/invitation/' . $token);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/invitation', $this->client->getResponse()->headers->get('Location'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertSame('Welcome <b>aboard</b>', $crawler->filter('[data-testid="auth-kit-invitation-message"]')->text());
        $email = $crawler->filter('input[name="registration_form[email]"]');
        self::assertSame('ana@example.com', $email->attr('value'));
        self::assertSame('disabled', $email->attr('disabled'));

        // A forged CSRF token is refused.
        $this->client->request('POST', '/invitation', ['registration_form' => [
            'password' => ['first' => 'correct-horse-battery', 'second' => 'correct-horse-battery'],
            '_token'   => 'forged',
        ]]);
        self::assertResponseStatusCodeSame(422);

        // A forged e-mail is ignored (locked field, forced server-side).
        $this->client->request('POST', '/invitation', ['registration_form' => [
            'email'    => 'mallory@example.com',
            'password' => ['first' => 'correct-horse-battery', 'second' => 'correct-horse-battery'],
            '_token'   => $crawler->filter('input[name="registration_form[_token]"]')->attr('value'),
        ]]);
        self::assertResponseRedirects('/home');

        $user = $this->user('ana@example.com');
        self::assertContains('ROLE_EDITOR', $user->getRoles());
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('mallory@example.com'));
        $accepted = $this->invitation('ana@example.com');
        self::assertNotNull($accepted->getAcceptedAt());
        self::assertSame('ana@example.com', $accepted->getAcceptedBy());
        self::assertSame(['ana@example.com -> ana@example.com'], $this->listener()->granted);
        self::assertInstanceOf(InvitationAcceptedEvent::class, $this->listener()->events[1] ?? null);

        // Single use: the same link answers the neutral page.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/invitation/' . $token);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="invalid"]');
    }

    public function testForgedRolesAndMissingCsrfAreRefused(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $crawler = $this->client->request('GET', '/admin/invitations/new');
        $token   = $crawler->filter('input[name="invitation[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/invitations/new', ['invitation' => [
            'email' => 'bob@example.com', 'ttlDays' => '7', 'roles' => ['ROLE_SUPER_ADMIN'], '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/admin/invitations/new', ['invitation' => ['email' => 'bob@example.com', 'ttlDays' => '7']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->mailer()->last());
    }

    public function testResendVoidsThePreviousLinkAndRevokeStopsIt(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $first = $this->invite('carol@example.com');
        $this->invite('zed@example.com');
        $id  = $this->invitation('carol@example.com')->getPublicId();
        $zed = $this->invitation('zed@example.com')->getPublicId();

        $crawler     = $this->client->request('GET', '/admin/invitations');
        $resendToken = (string) $crawler->filter('form[action="/admin/invitations/' . $id . '/resend"] input[name="csrf_only[_token]"]')->attr('value');
        $revokeToken = (string) $crawler->filter('form[action="/admin/invitations/' . $id . '/revoke"] input[name="csrf_only[_token]"]')->attr('value');
        $zedToken    = (string) $crawler->filter('form[action="/admin/invitations/' . $zed . '/revoke"] input[name="csrf_only[_token]"]')->attr('value');
        self::assertNotSame($resendToken, $revokeToken, 'Token ids bind the action.');
        self::assertNotSame($revokeToken, $zedToken, 'Token ids bind the row.');

        // CSRF-only actions: no token, the token of another action or of another row, are refused.
        $this->client->request('POST', '/admin/invitations/' . $id . '/resend');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/invitations/' . $id . '/resend', ['csrf_only' => ['_token' => $revokeToken]]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/invitations/' . $id . '/revoke', ['csrf_only' => ['_token' => $zedToken]]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/invitations/' . $id . '/resend', ['csrf_only' => ['_token' => $resendToken]]);
        self::assertResponseStatusCodeSame(303);
        $second = $this->mailer()->last();
        self::assertNotNull($second);
        self::assertNotSame($first, $second->acceptUrl);
        self::assertSame(2, $second->invitation->sendCount);
        self::assertInstanceOf(InvitationResentEvent::class, $this->listener()->events[2] ?? null);

        $this->client->request('POST', '/admin/invitations/' . $id . '/revoke', ['csrf_only' => ['_token' => $revokeToken]]);
        self::assertResponseStatusCodeSame(303);
        self::assertInstanceOf(InvitationRevokedEvent::class, $this->listener()->events[3] ?? null);
        self::assertNotNull($this->invitation('carol@example.com')->getRevokedAt());

        // Closed rows have no actions.
        $crawler = $this->client->request('GET', '/admin/invitations', ['status' => 'revoked']);
        self::assertSame(['carol@example.com'], $crawler->filter('[data-testid="nowo-auth-kit-invitation-email"]')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(0, $crawler->filter('form[action="/admin/invitations/' . $id . '/resend"]'));

        // No longer open: revoke and resend change nothing.
        $this->client->request('POST', '/admin/invitations/' . $id . '/revoke', ['csrf_only' => ['_token' => $revokeToken]]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('POST', '/admin/invitations/' . $id . '/resend', ['csrf_only' => ['_token' => $resendToken]]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(4, $this->listener()->events);

        // A valid token for a row that no longer exists.
        $this->entityManager()->createQuery('DELETE FROM ' . Invitation::class . ' i WHERE i.email = :email')->setParameter('email', 'zed@example.com')->execute();
        $this->client->request('POST', '/admin/invitations/' . $zed . '/revoke', ['csrf_only' => ['_token' => $zedToken]]);
        self::assertResponseStatusCodeSame(404);

        // A replaced link, a revoked link and an unknown token answer the same page.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', (string) parse_url($first, PHP_URL_PATH));
        $this->client->followRedirect();
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="invalid"]');
        $invalidBody = $this->noticeBody();
        $this->client->request('GET', (string) parse_url($second->acceptUrl, PHP_URL_PATH));
        $this->client->followRedirect();
        self::assertSame($invalidBody, $this->noticeBody());
        $this->client->request('GET', '/invitation/' . str_repeat('A', 43));
        $this->client->followRedirect();
        self::assertSame($invalidBody, $this->noticeBody());
    }

    public function testUndeliveredAndRateLimitedInvitations(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $this->mailer()->fail = true;
        $crawler              = $this->client->request('GET', '/admin/invitations/new');
        $this->client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => 'dan@example.com']));
        self::assertResponseStatusCodeSame(303);
        $created = $this->listener()->events[0] ?? null;
        self::assertInstanceOf(InvitationCreatedEvent::class, $created);
        self::assertSame(InvitationCreatedEvent::DELIVERY_NONE, $created->delivery);
        $this->client->followRedirect();
        self::assertStringNotContainsString('/invitation/', (string) $this->client->getResponse()->getContent(), 'The link stays secret.');
        $this->mailer()->fail = false;

        $limiter = self::getContainer()->get(AuthKitAttemptLimiter::class);
        self::assertInstanceOf(AuthKitAttemptLimiter::class, $limiter);
        for ($i = 0; $i < 20; ++$i) {
            $limiter->hit('invitation_invite:default:admin@example.com', 3600);
        }
        $crawler = $this->client->request('GET', '/admin/invitations/new');
        $this->client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => 'eve@example.com']));
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->mailer()->last());
        self::assertCount(1, $this->listener()->events);
    }

    public function testPayloadPrefillAndRefusedSignIn(): void
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);
        $manager->invite(null, 'default', new InvitationRequest('blocked@example.com', payload: ['newsletter' => 1, 'nickname' => 'Bee', 'email' => 'ignored@example.com', 'password' => 'never', 'terms' => true]));
        $path = (string) parse_url((string) $this->mailer()->last()?->acceptUrl, PHP_URL_PATH);

        $this->client->request('GET', $path);
        $crawler = $this->client->followRedirect();
        self::assertSame('checked', $crawler->filter('input[name="registration_form[newsletter]"]')->attr('checked'));
        self::assertSame('Bee', $crawler->filter('input[name="registration_form[nickname]"]')->attr('value'));
        self::assertNull($crawler->filter('input[name="registration_form[terms]"]')->attr('checked'), 'Unmapped fields are not pre-filled.');
        self::assertSame('blocked@example.com', $crawler->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertSame('', (string) $crawler->filter('input[name="registration_form[password][first]"]')->attr('value'));

        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        // The account exists, the user checker refuses the sign-in: back to the sign-in page.
        self::assertResponseRedirects('/login');
        self::assertNotNull($this->entityManager()->getRepository(KernelUser::class)->find('blocked@example.com'));
    }

    public function testPurgeCommandDeletesClosedInvitations(): void
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);
        foreach (['old-revoked@example.com', 'old-expired@example.com', 'fresh@example.com'] as $email) {
            $manager->invite(null, 'default', new InvitationRequest($email));
        }
        $em = $this->entityManager();
        $em->createQuery('UPDATE ' . Invitation::class . ' i SET i.revokedAt = :old WHERE i.email = :email')
            ->setParameter('old', new DateTimeImmutable('-200 days'))->setParameter('email', 'old-revoked@example.com')->execute();
        $em->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :old WHERE i.email = :email')
            ->setParameter('old', new DateTimeImmutable('-200 days'))->setParameter('email', 'old-expired@example.com')->execute();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:auth-kit:invitations:purge'));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => 'x']));
        self::assertSame(Command::INVALID, $tester->execute(['--auth-profile' => 'nope']));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('2 invitation(s) would be deleted', $tester->getDisplay());
        self::assertCount(3, $em->getRepository(Invitation::class)->findAll());

        self::assertSame(Command::SUCCESS, $tester->execute(['--auth-profile' => 'default', '--days' => '90', '--include-accepted' => true]));
        self::assertStringContainsString('Deleted 2 invitation(s)', $tester->getDisplay());
        $em->clear();
        self::assertSame(['fresh@example.com'], array_map(static fn (Invitation $i): string => $i->getEmail(), $em->getRepository(Invitation::class)->findAll()));
        self::assertInstanceOf(InvitationsPurgedEvent::class, $this->listener()->purged[0] ?? null);
    }

    public function testExpiredRateLimitedSignedInAndRefusedAcceptances(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $url   = $this->invite('erin@example.com');
        $path  = (string) parse_url($url, PHP_URL_PATH);
        $token = substr($path, strlen('/invitation/'));

        // Signed in: sign out first (the token is not taken).
        $this->client->request('GET', $path);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="signed_in"]');
        $this->client->request('GET', '/logout');

        // Grant refused: nothing is kept, the invitation stays pending.
        $this->listener()->refuse = true;
        $this->client->request('GET', $path);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('erin@example.com'));
        self::assertNull($this->invitation('erin@example.com')->getAcceptedAt());
        $this->listener()->refuse = false;

        // Tokenless page without a token in the session.
        $this->client->request('GET', '/invitation');
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="invalid"]');

        // Invalid form: 422 with the form again.
        $this->client->request('GET', $path);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'one-password-123',
            'registration_form[password][second]' => 'another-password-123',
        ]));
        self::assertResponseStatusCodeSame(422);

        // Expired (still the current token).
        $em = $this->entityManager();
        $em->createQuery('UPDATE ' . Invitation::class . ' i SET i.expiresAt = :past')->setParameter('past', new DateTimeImmutable('-1 day'))->execute();
        $this->client->request('GET', $path);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="expired"]');
        self::assertStringNotContainsString('erin@example.com', (string) $this->client->getResponse()->getContent());

        // Rate limited per client IP.
        $limiter = self::getContainer()->get(AuthKitAttemptLimiter::class);
        self::assertInstanceOf(AuthKitAttemptLimiter::class, $limiter);
        for ($i = 0; $i < 50; ++$i) {
            $limiter->hit('invitation_accept:default:127.0.0.1', 900);
        }
        $this->client->request('GET', '/invitation/' . $token);
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="rate_limited"]');
    }

    public function testInvitingAnExistingAccountLooksTheSameAndFailsNeutrallyOnAcceptance(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $url = $this->invite('member@example.com');
        self::assertStringStartsWith('https://app.example.test/invitation/', $url, 'Same outcome as for a new address.');

        $this->client->request('GET', '/logout');
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-invitation_accept"] form')->form([
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        self::assertSelectorExists('[data-nowo-auth-kit-invitation="not_accepted"]');
        self::assertSame('member-password-123', $this->user('member@example.com')->getPassword(), 'The existing account is untouched.');
        self::assertNull($this->invitation('member@example.com')->getAcceptedAt());
    }

    public function testOpenRegistrationIsClosedInInviteOnlyMode(): void
    {
        $this->client->request('GET', '/register');
        self::assertResponseRedirects('/login');
    }

    public function testListFiltersAndPagination(): void
    {
        $this->client->loginUser($this->user('admin@example.com'));
        $this->invite('fay@example.com');
        $this->invite('gus@example.com');

        $crawler = $this->client->request('GET', '/admin/invitations', ['q' => 'fay', 'status' => 'pending', 'per_page' => 999, 'page' => 99]);
        self::assertResponseIsSuccessful();
        self::assertSame(['fay@example.com'], $crawler->filter('[data-testid="nowo-auth-kit-invitation-email"]')->each(static fn (Crawler $c): string => trim($c->text())));

        $crawler = $this->client->request('GET', '/admin/invitations', ['status' => 'revoked']);
        self::assertCount(0, $crawler->filter('[data-testid="nowo-auth-kit-invitation-email"]'));
        $crawler = $this->client->request('GET', '/admin/invitations', ['status' => 'all', 'per_page' => 10]);
        self::assertCount(2, $crawler->filter('[data-testid="nowo-auth-kit-invitation-email"]'));
    }

    private function invite(string $email): string
    {
        $crawler = $this->client->request('GET', '/admin/invitations/new');
        $this->client->submit($crawler->filter('[data-testid="nowo-auth-kit-invitation-new"] form')->form(['invitation[email]' => $email]));
        self::assertResponseStatusCodeSame(303);

        return (string) $this->mailer()->last()?->acceptUrl;
    }

    /** @phpstan-impure */
    private function noticeBody(): string
    {
        return (new Crawler((string) $this->client->getResponse()->getContent()))->filter('[data-nowo-ui-page="auth"]')->html();
    }

    private function invitation(string $email): Invitation
    {
        $em = $this->entityManager();
        $em->clear();
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => $email]);
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

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
