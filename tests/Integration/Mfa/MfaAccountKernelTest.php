<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaDisabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodesRegeneratedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDeviceAddedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The account page (1.34): trusted devices, step-up for disable and new recovery codes, CSRF, impersonation refused.
 */
class MfaAccountKernelTest extends MfaKernelTestCase
{
    public function testTrustedDeviceSkipsTheCodeUntilForgotten(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);
        $cookie = $this->client->getCookieJar()->get(MfaTrustedDevices::COOKIE);
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertCount(1, $this->eventsOf(MfaTrustedDeviceAddedEvent::class));
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/home', null, 'A trusted device skips the second step.');
        self::assertSame('trusted_device', $this->eventsOf(MfaChallengePassedEvent::class)[1]->method);
        self::assertNotSame($cookie->getValue(), $this->client->getCookieJar()->get(MfaTrustedDevices::COOKIE)?->getValue(), 'The token is rotated on use.');

        // A copy of the old (rotated) cookie no longer trusts anything.
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertCount(1, $crawler->filter('[data-testid="auth-kit-mfa-devices"] li'));
        $this->client->submit($crawler->filter('[data-testid="auth-kit-mfa-forget"]')->form());
        self::assertResponseRedirects('/account/security/mfa', 303);
        self::assertSame('user', $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[0]->reason);
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/login/mfa', null, 'A forgotten device is challenged again.');
    }

    public function testDisableAndNewCodesNeedACurrentCode(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret, $codes] = $this->enrol();
        $crawler          = $this->client->request('GET', '/account/security/mfa');
        self::assertSelectorTextContains('[data-testid="auth-kit-mfa-status"]', 'On since');

        $this->client->submit($crawler->filter('form[name="mfa_recovery_codes"]')->form(['mfa_recovery_codes[code]' => '000000']));
        self::assertResponseRedirects('/account/security/mfa', 303);
        self::assertSame([], $this->eventsOf(MfaRecoveryCodesRegeneratedEvent::class));

        $crawler = $this->client->request('GET', '/account/security/mfa');
        $page    = $this->client->submit($crawler->filter('form[name="mfa_recovery_codes"]')->form(['mfa_recovery_codes[recovery_code]' => $codes[1]]));
        self::assertResponseIsSuccessful();
        $fresh = $page->filter('[data-testid="auth-kit-mfa-codes"] li')->each(static fn (Crawler $li): string => $li->text());
        self::assertCount(10, $fresh);
        self::assertNotContains($codes[2], $fresh);
        self::assertCount(1, $this->eventsOf(MfaRecoveryCodesRegeneratedEvent::class));

        $crawler = $this->client->request('GET', '/account/security/mfa');
        $this->client->submit($crawler->filter('form[name="mfa_disable"]')->form(['mfa_disable[code]' => $this->code($secret)]));
        self::assertResponseRedirects('/account/security/mfa', 303);
        self::assertCount(1, $this->eventsOf(MfaDisabledEvent::class));
        self::assertFalse($this->manager()->isEnabled($this->user('member@example.com')));
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/home');
    }

    public function testEnableWithAWrongCodeAndForgedForms(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/account/security/mfa');
        $this->client->request('POST', '/account/security/mfa/enable', ['mfa_enable' => ['code' => '123456', '_token' => 'forged']]);
        self::assertResponseRedirects('/account/security/mfa', 303);
        self::assertFalse($this->manager()->isEnabled($this->user('member@example.com')));

        $this->client->request('POST', '/account/security/mfa/devices/all/forget', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForgetAllAndUnknownDevice(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);
        $crawler = $this->client->request('GET', '/account/security/mfa');
        $forms   = $crawler->filter('[data-testid="auth-kit-mfa-forget-all"]');
        self::assertCount(1, $forms);
        $all = $forms->form();
        $this->client->submit($all);
        self::assertSame(1, $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[0]->count);
        $this->client->submit($all);
        self::assertResponseRedirects('/account/security/mfa', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already forgotten', $crawler->text());
    }

    public function testAnonymousAndImpersonatorsAreRefused(): void
    {
        $this->client->request('GET', '/account/security/mfa');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->user('support@example.com'));
        $this->client->request('GET', '/mfa-home?_switch_user=member@example.com');
        $this->client->request('GET', '/account/security/mfa');
        self::assertResponseStatusCodeSame(403, 'An impersonator cannot manage the user\'s second factor.');
    }
}
