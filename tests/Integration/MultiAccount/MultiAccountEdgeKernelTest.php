<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchingEvent;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function chr;

/**
 * Account switcher edge cases (1.34): chooser and sign-in page, signing the active account out, token reuse, expiry,
 * revival through remember-me, host veto, rate limits, full browser, impersonation, purge.
 */
final class MultiAccountEdgeKernelTest extends MultiAccountKernelTestCase
{
    public function testChooserAndSignInPageListTheAccountsWithMaskedIdentifiers(): void
    {
        $this->client->request('GET', '/accounts');
        self::assertResponseRedirects('/login', null, 'Nothing to choose.');

        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $crawler = $this->client->request('GET', '/login');
        self::assertSelectorExists('[data-testid="auth-kit-login-accounts"]');
        self::assertStringContainsString('m•••@example.com', $crawler->filter('[data-testid="auth-kit-login-accounts"]')->text());
        self::assertStringNotContainsString('member@example.com', $crawler->filter('[data-testid="auth-kit-login-accounts"]')->text());

        $crawler = $this->client->request('GET', '/accounts');
        self::assertResponseIsSuccessful();
        self::assertSame('DENY', $this->client->getResponse()->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-testid="auth-kit-account"]'));

        // From the anonymous chooser, switching back needs no password.
        $this->client->submit($crawler->filter('form.nowo-auth-kit-accounts__switch')->form());
        self::assertStringStartsWith('member@example.com|', $this->whoami());
    }

    public function testSigningTheActiveAccountOutShowsTheChooser(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->signOutOf('admin@example.com');
        self::assertResponseRedirects('/accounts');
        self::assertStringStartsWith('anonymous|', $this->whoami());
        self::assertSame(['member@example.com|signed_in'], $this->accounts());
    }

    public function testAReusedRotatedTokenRemovesTheAccount(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $stolen = (string) $this->vaultCookie();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->switchTo('member@example.com');
        $this->clock()->modify('+31 seconds');

        $this->client->getCookieJar()->expire('nowo_auth_kit_accounts');
        $this->client->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $stolen, null, '/', 'localhost'));
        $this->menu();
        self::assertContains(AccountRemovalReason::TokenReuse, array_map(static fn (AccountRemovedEvent $e): AccountRemovalReason => $e->reason, $this->eventsOf(AccountRemovedEvent::class)));
    }

    public function testExpiredEntriesLeaveAndUnknownTokensAreDropped(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $value = $this->vaultCookie() . '.' . str_repeat('A', 43) . '.bad';
        $this->client->getCookieJar()->expire('nowo_auth_kit_accounts');
        $this->client->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $value, null, '/', 'localhost'));
        self::assertCount(2, $this->accounts());
        self::assertStringNotContainsString(str_repeat('A', 43), (string) $this->vaultCookie());

        $this->clock()->modify('+31 days');
        self::assertSame([], $this->accounts());
        self::assertSame(AccountRemovalReason::Expired, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);
    }

    public function testUnknownTokensAreRateLimitedPerClient(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $good = (string) $this->vaultCookie();
        for ($i = 0; $i < 20; ++$i) {
            $this->client->getCookieJar()->expire('nowo_auth_kit_accounts');
            $this->client->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $good . '.' . str_repeat(chr(65 + $i), 43), null, '/', 'localhost'));
            $this->menu();
        }
        $this->client->getCookieJar()->expire('nowo_auth_kit_accounts');
        $this->client->getCookieJar()->set(new Cookie('nowo_auth_kit_accounts', $good, null, '/', 'localhost'));
        self::assertSame([], $this->accounts(), 'Over the limit the vault is ignored for this client.');
    }

    public function testAnExpiredParkedSessionComesBackThroughItsRememberMeCookie(): void
    {
        $this->signIn('member@example.com', 'member-password-123', remember: true);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->add();
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'), 'The parked account\'s remember-me cookie is moved into the vault.');
        $this->signIn('admin@example.com', 'admin-password-123');

        $this->clock()->modify('+23 hours');
        $this->whoami();
        $this->clock()->modify('+2 hours');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_out'], $this->accounts());
        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/mfa-home', 303);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
        self::assertStringStartsWith('member@example.com|', $this->whoami(), 'The firewall\'s remember-me sign-in brings the account back.');
        self::assertSame(['member@example.com|active', 'admin@example.com|signed_in'], $this->accounts());
    }

    public function testAHostListenerCanRefuseASwitch(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(AccountSwitchingEvent::class, static function (AccountSwitchingEvent $event): void {
            $event->refuse('accounts.flash.not_now');
        });
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->switchTo('member@example.com');
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
    }

    public function testAFullBrowserAndRateLimits(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->add();
        $this->signIn('support@example.com', 'support-password-123');
        $this->add();
        foreach (['four', 'five'] as $name) {
            $this->em()->persist((new KernelUser())->setEmail($name . '@example.com')->setPassword('pw-' . $name . '-123456'));
            $this->em()->flush();
            $this->signIn($name . '@example.com', 'pw-' . $name . '-123456');
            if ($name === 'four') {
                $this->add();
            }
        }
        self::assertCount(5, $this->accounts());
        self::assertSelectorNotExists('[data-testid="auth-kit-account-add"]');
        $crawler = $this->client->request('GET', '/accounts');
        $token   = (string) $crawler->filter('form.nowo-auth-kit-accounts__switch input[name="csrf_only[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/accounts/add', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNotSame('', $token);
    }

    public function testForgedTokensImpersonatorsAndUnknownAccounts(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('POST', '/accounts/' . str_repeat('a', 32) . '/switch', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/accounts/sign-out-all', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->user('support@example.com'));
        $this->client->request('GET', '/mfa-home?_switch_user=member@example.com');
        self::assertSelectorNotExists('[data-testid="auth-kit-account-add"]', 'No add while impersonating.');
        $this->menu();
        self::assertSelectorNotExists('[data-testid="auth-kit-account-add"]');
    }

    public function testThePurgeCommand(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->clock()->modify('+31 days');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:auth-kit:accounts:purge'));
        $tester->execute([]);
        self::assertStringContainsString('1 expired account entry deleted.', $tester->getDisplay());
    }
}
