<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use ArrayObject;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchingEvent;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function array_map;
use function chr;
use function explode;
use function hash;
use function sort;
use function str_repeat;

/**
 * Regression tests of SEC-004 Appendix DE (the audit's proofs of concept, in their fixed form).
 */
final class MultiAccountAuditDeKernelTest extends MultiAccountKernelTestCase
{
    /** DE-M1: a revoked parked account never comes back, even once its revoked row was purged. */
    public function testARevokedAndPurgedParkedAccountDoesNotRevive(): void
    {
        $this->twoAccounts(memberRemember: true);
        $row    = $this->menu()->filter('[data-testid="auth-kit-account"]')->reduce(static fn ($r): bool => $r->filter('.nowo-auth-kit-accounts__name')->text() === 'member@example.com');
        $switch = $row->filter('form.nowo-auth-kit-accounts__switch')->form();
        $conn   = $this->em()->getConnection();
        $id     = (string) $conn->fetchOne('SELECT public_id FROM user_kit_session WHERE user_id = ? AND revoked_at IS NULL', ['member@example.com']);
        self::assertTrue($this->sessions()->revoke($this->user('member@example.com'), $id));
        $conn->executeStatement('DELETE FROM user_kit_session WHERE revoked_at IS NOT NULL');

        $this->client->submit($switch);
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertContains(AccountRemovalReason::Revoked, $this->reasons());
    }

    /** DE-M2: a reused token signs the account out everywhere, the thief who switched to it first included. */
    public function testTokenReuseEvictsTheThief(): void
    {
        $this->twoAccounts();
        $stolen = (string) $this->vaultCookie();
        $thief  = new KernelBrowser($this->client->getKernel());
        $thief->disableReboot();
        $thief->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $stolen));
        $crawler = $thief->request('GET', '/accounts');
        self::assertCount(1, $crawler->filter('[data-testid="auth-kit-account"]'), 'The account active in the victim browser is not offered (DE-I6).');
        $thief->submit($crawler->filter('form.nowo-auth-kit-accounts__switch')->first()->form());
        $thief->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $thief->getResponse()->getContent());

        $this->clock()->modify('+60 seconds');
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertContains(AccountRemovalReason::TokenReuse, $this->reasons());
        self::assertSame([], $this->em()->getConnection()->fetchAllAssociative("SELECT id FROM auth_kit_account_vault WHERE user_identifier = 'member@example.com'"));

        self::assertSame('vault_token_reuse', $this->em()->getConnection()->fetchOne("SELECT revoked_reason FROM user_kit_session WHERE user_id = 'member@example.com' AND revoked_at IS NOT NULL"));
        $thief->request('GET', '/mfa-home');
        self::assertTrue($thief->getResponse()->isRedirect(), 'The thief is signed out.');
    }

    /** DE-M3: unknown tokens from another client of the same IP never empty a vault; the sign-out still ends every account. */
    public function testUnknownTokensFromTheSameIpDoNotDisableTheVault(): void
    {
        $this->twoAccounts();
        $attacker = new KernelBrowser($this->client->getKernel());
        $attacker->disableReboot();
        for ($i = 0; $i < 25; ++$i) {
            $attacker->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', str_repeat('A', 42) . chr(65 + $i)));
            $attacker->request('GET', '/accounts-menu');
        }
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        $this->client->request('GET', '/logout');
        self::assertSame([], $this->em()->getConnection()->fetchAllAssociative("SELECT user_id FROM user_kit_session WHERE user_id = 'member@example.com'"));
        self::assertSame([], $this->em()->getConnection()->fetchAllAssociative('SELECT id FROM auth_kit_account_vault'));
    }

    /** DE-M4: an exception in the middle of a switch leaves the browser on its previous account and session. */
    public function testAnExceptionDuringTheHopRollsBack(): void
    {
        $this->twoAccounts();
        $before     = (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $failing = new ArrayObject(['on' => true]);
        $dispatcher->addListener(AccountSwitchingEvent::class, static function () use ($failing): void {
            if ($failing['on'] === true) {
                throw new RuntimeException('host listener bug');
            }
        });

        $this->switchTo('member@example.com');
        self::assertResponseRedirects();
        self::assertSame($before, (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue());
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
        $failing['on'] = false;
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        $this->switchTo('member@example.com');
        self::assertStringStartsWith('member@example.com|', $this->whoami());
    }

    /** DE-L1: the switcher partial masks identifiers for an anonymous browser. */
    public function testThePartialMasksIdentifiersWhenAnonymous(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        self::assertStringStartsWith('anonymous|', $this->whoami());
        self::assertStringNotContainsString('member@example.com', $this->client->request('GET', '/accounts-menu')->html());
        self::assertStringNotContainsString('member@example.com', $this->client->request('GET', '/login')->html());
    }

    /** DE-L2: a target path with a control character is not followed. */
    public function testATargetPathWithAControlCharacterIsIgnored(): void
    {
        $this->twoAccounts();
        $row  = $this->menu()->filter('[data-testid="auth-kit-account"]')->reduce(static fn ($r): bool => $r->filter('.nowo-auth-kit-accounts__name')->text() === 'member@example.com');
        $form = $row->filter('form.nowo-auth-kit-accounts__switch')->form();
        $form->setValues(['_target_path' => "/\t/evil.example"]);
        $this->client->submit($form);
        self::assertResponseRedirects('/mfa-home', 303);
    }

    /** DE-L3 / DE-L7: a request of the same browser that left before a switch learns the current tokens, no false theft. */
    public function testOverlappingRequestsOfOneBrowserWriteTheCurrentTokens(): void
    {
        $this->twoAccounts();
        $sid = (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $old = (string) $this->vaultCookie();
        $this->switchTo('member@example.com');
        $afterSwitch = (string) $this->vaultCookie();
        $tab         = $this->tab($sid, $old);
        $this->clock()->modify('+1 second');
        $tab->request('GET', '/accounts-menu');
        $written = explode('.', (string) $tab->getCookieJar()->get('nowo_auth_kit_accounts')?->getValue());
        $current = explode('.', $afterSwitch);
        sort($written);
        sort($current);
        self::assertSame($current, $written, 'Both racing responses write the same tokens.');

        $this->client->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $afterSwitch));
        $this->clock()->modify('+60 seconds');
        self::assertSame(['member@example.com|active', 'admin@example.com|signed_in'], $this->accounts());
        self::assertSame([], $this->em()->getConnection()->fetchAllAssociative('SELECT user_id FROM user_kit_session WHERE revoked_at IS NOT NULL'));
        self::assertNotContains(AccountRemovalReason::TokenReuse, $this->reasons());
    }

    /** DE-M5: within the grace period, a token rotated by another browser (a thief) is ignored, then counts as reuse. */
    public function testTheGracePeriodDoesNotHideATheft(): void
    {
        $this->twoAccounts();
        $stolen = (string) $this->vaultCookie();
        $thief  = new KernelBrowser($this->client->getKernel());
        $thief->disableReboot();
        $thief->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $stolen));
        $crawler = $thief->request('GET', '/accounts');
        $thief->submit($crawler->filter('form.nowo-auth-kit-accounts__switch')->first()->form());
        $thief->getCookieJar()->expire('nowo_auth_kit_accounts');
        $this->clock()->modify('+10 seconds');
        self::assertSame(['admin@example.com|active'], $this->accounts(), 'Not caught up from another browser.');
        self::assertCount(2, explode('.', (string) $this->vaultCookie()), 'The rotated-away token is kept as evidence.');

        $this->clock()->modify('+120 seconds');
        $this->accounts();
        self::assertContains(AccountRemovalReason::TokenReuse, $this->reasons());
        $thief->request('GET', '/mfa-home');
        self::assertTrue($thief->getResponse()->isRedirect(), 'The thief is signed out.');
    }

    /** DE-L3: a previous token whose blob cannot be opened stays in the cookie; a forged blob drops it. */
    public function testPreviousTokensThatCannotCatchUp(): void
    {
        $this->twoAccounts();
        $sid = (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $old = (string) $this->vaultCookie();
        $this->switchTo('member@example.com');
        $conn = $this->em()->getConnection();
        $conn->executeStatement("UPDATE auth_kit_account_vault SET previous_sealed = NULL WHERE user_identifier = 'admin@example.com'");
        $tab = $this->tab($sid, $old);
        $tab->request('GET', '/accounts-menu');
        self::assertCount(2, explode('.', (string) $tab->getCookieJar()->get('nowo_auth_kit_accounts')?->getValue()), 'The admin token is kept for a later request.');

        $conn->executeStatement("UPDATE auth_kit_account_vault SET previous_sealed = 'forged' WHERE user_identifier = 'admin@example.com'");
        $tab->request('GET', '/accounts-menu');
        self::assertCount(1, explode('.', (string) $tab->getCookieJar()->get('nowo_auth_kit_accounts')?->getValue()), 'A forged blob drops the token.');
    }

    /**
     * Another tab of the same browser whose request carries the given session and vault cookies.
     */
    private function tab(string $sessionId, string $vault): KernelBrowser
    {
        $tab = new KernelBrowser($this->client->getKernel());
        $tab->disableReboot();
        $tab->getCookieJar()->set(new Cookie('MOCKSESSID', $sessionId));
        $tab->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $vault));

        return $tab;
    }

    /** DE-L5: an admin-issued link stays an admin-issued link when the second factor completes on another request. */
    public function testAnAdminIssuedFirstFactorNeverEnrolsThroughMfaCompletion(): void
    {
        $member = $this->user('member@example.com');
        $cache  = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->save($cache->getItem(AdminMagicLinkIssuer::ISSUED_PREFIX . hash('sha256', 'admin-hash'))->set(true));
        $session = new Session(new MockArraySessionStorage());
        $session->set(AccountVault::ADD_ATTRIBUTE, ['default' => $this->clock()->now()->getTimestamp() + 600]);
        $link = Request::create('/login/check?hash=admin-hash');
        $link->setSession($session);
        $inner      = new UsernamePasswordToken($member, 'main', ['ROLE_USER']);
        $pending    = new MfaPendingToken($inner, 'main', 'default', 'login_link', MfaPendingToken::MODE_CHALLENGE, $this->clock()->now()->getTimestamp());
        $subscriber = self::getContainer()->get(MultiAccountSubscriber::class);
        self::assertInstanceOf(MultiAccountSubscriber::class, $subscriber);
        $subscriber->onLoginSuccess(new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), new SelfValidatingPassport(new UserBadge('member@example.com')), $pending, $link, null, 'main'));
        self::assertTrue($session->get(MultiAccountSubscriber::ADMIN_LINK_ATTRIBUTE));

        $challenge = Request::create('/mfa/challenge', 'POST');
        $challenge->setSession($session);
        $subscriber->onMfaCompleted(new MfaLoginCompletedEvent('default', $member, $inner, $challenge, 'login_link'));
        self::assertFalse($session->has(MultiAccountSubscriber::ADMIN_LINK_ATTRIBUTE));
        self::assertSame([], $this->em()->getConnection()->fetchAllAssociative('SELECT id FROM auth_kit_account_vault'));
    }

    private function twoAccounts(bool $memberRemember = false): void
    {
        $this->signIn('member@example.com', 'member-password-123', remember: $memberRemember);
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    /**
     * @return list<AccountRemovalReason>
     */
    private function reasons(): array
    {
        return array_map(static fn (AccountRemovedEvent $e): AccountRemovalReason => $e->reason, $this->eventsOf(AccountRemovedEvent::class));
    }

    private function sessions(): UserSessionManager
    {
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);

        return $sessions;
    }
}
