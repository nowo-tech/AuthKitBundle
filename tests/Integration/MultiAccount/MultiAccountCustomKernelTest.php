<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Host choices (1.34): logout of the active account only, host gate and presenter, rate limits, no revival, cookie
 * options, cross-tab notice.
 */
final class MultiAccountCustomKernelTest extends MultiAccountKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiAccountCustomTestKernel::class;
    }

    public function testHostPresenterCookieAndCurrentLogout(): void
    {
        $this->signIn('member@example.com', 'member-password-123', remember: true);
        $this->add();
        $cookie = null;
        foreach ($this->client->getCookieJar()->all() as $c) {
            if ($c->getName() === 'nowo_auth_kit_accounts') {
                $cookie = $c;
            }
        }
        self::assertNotNull($cookie);
        self::assertSame('strict', $cookie->getSameSite());
        self::assertNotNull($cookie->getExpiresTime(), 'Persistent with cookie.lifetime.');
        $this->signIn('admin@example.com', 'admin-password-123');

        $crawler = $this->menu();
        self::assertSame(['Admin Account', 'Member Account'], $crawler->filter('.nowo-auth-kit-accounts__name')->each(static fn ($n): string => $n->text()));
        self::assertSame('/avatars/admin@example.com.png', $crawler->filter('.nowo-auth-kit-accounts__avatar img')->first()->attr('src'));
        self::assertStringContainsString('admin@example.com', $crawler->text(), 'identifier_display: full.');
        self::assertCount(1, $crawler->filter('[data-nowo-auth-kit-accounts-notice] script'));

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/accounts', null, 'logout_scope: current shows the chooser.');
        self::assertSame(['Member Account|signed_in'], $this->accounts());

        // No revival: once the parked session expired the account leaves although it had a remember-me cookie.
        $this->clock()->modify('+2 days');
        self::assertSame([], $this->accounts());
    }

    public function testTheHostGateKeepsAnAccountOutAndRateLimits(): void
    {
        $this->signIn('support@example.com', 'support-password-123');
        $this->menu();
        $this->client->submit($this->menu()->filter('[data-testid="auth-kit-account-add"]')->closest('form')?->form() ?? self::fail('No add.'));
        self::assertResponseRedirects('/accounts', 303, 'support@ cannot be parked: add is refused.');
        self::assertSame('support@example.com|' . UsernamePasswordToken::class, $this->whoami());

        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('support@example.com', 'support-password-123');
        self::assertSame(['Member Account|signed_in'], $this->accounts(), 'The gate keeps support@ out of the switcher.');

        $this->switchTo('Member Account');
        self::assertStringStartsWith('member@example.com|', $this->whoami(), 'support@ (not parkable) was signed out on the way.');
        $this->client->submit($this->menu()->filter('[data-testid="auth-kit-account-add"]')->closest('form')?->form() ?? self::fail('No add.'));
        self::assertResponseRedirects('/accounts', 303, 'The third add in the window is rate limited.');
        $this->client->followRedirect();
        self::assertStringContainsString('Too many attempts', (string) $this->client->getResponse()->getContent());
    }
}
