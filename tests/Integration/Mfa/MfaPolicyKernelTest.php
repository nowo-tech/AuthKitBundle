<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\Twig\MfaExtension;
use Symfony\Component\DomCrawler\Crawler;

use function str_replace;

/**
 * Required MFA (1.34): `required_roles`, the grace period with its reminder, then the forced enrolment page (enrol or
 * sign out, nothing else), the recovery codes shown once and the completed sign-in.
 */
final class MfaPolicyKernelTest extends MfaKernelTestCase
{
    public function testGraceThenForcedEnrolment(): void
    {
        self::assertSame(MfaRequirement::Required, $this->manager()->requirement($this->user('admin@example.com')));
        self::assertSame(MfaRequirement::Optional, $this->manager()->requirement($this->user('member@example.com')));

        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/home', null, 'Inside the grace period the sign-in completes.');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertStringContainsString('Required for your account: set it up before', $crawler->text());
        $this->client->request('GET', '/logout');

        $this->clock()->modify('+8 days');
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa/setup');
        $this->client->request('GET', '/login/mfa');
        self::assertResponseRedirects('/login/mfa/setup', null, 'The challenge sends a setup sign-in to the setup page.');
        $this->client->request('GET', '/mfa-home');
        self::assertResponseRedirects('/login/mfa/setup');

        $crawler = $this->client->request('GET', '/login/mfa/setup');
        self::assertResponseIsSuccessful();
        self::assertSame('DENY', $this->client->getResponse()->headers->get('X-Frame-Options'));
        self::assertStringStartsWith('data:image/', (string) $crawler->filter('[data-testid="auth-kit-mfa-qr"]')->attr('src'), 'The QR code is rendered server-side.');
        $secret = str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text());

        $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => '123456']));
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/login/mfa/setup', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseRedirects('/login/mfa/setup', 303, 'Continue before enrolling goes back to the setup page.');

        $crawler = $this->client->request('GET', '/login/mfa/setup');
        $page    = $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => $this->code($secret)]));
        self::assertResponseIsSuccessful();
        $codes = $page->filter('[data-testid="auth-kit-mfa-codes"] li')->each(static fn (Crawler $li): string => $li->text());
        self::assertCount(10, $codes);
        self::assertStringStartsWith('data:text/plain', (string) $page->filter('[data-testid="auth-kit-mfa-codes-download"]')->attr('href'));

        $this->client->submit($page->filter('[data-testid="auth-kit-mfa-continue"]')->form());
        self::assertResponseRedirects('/home', 303);
        $this->client->request('GET', '/mfa-home');
        self::assertStringStartsWith('admin@example.com|', (string) $this->client->getResponse()->getContent());
        self::assertSame('enrolment', $this->eventsOf(MfaChallengePassedEvent::class)[0]->method);
    }

    public function testTheChallengeAndSetupPagesSendOthersAway(): void
    {
        $this->client->request('GET', '/login/mfa');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/login/mfa/setup');
        self::assertResponseRedirects('/login');
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/login/mfa');
        self::assertResponseRedirects('/home', null, 'A completed sign-in goes to the app.');
    }

    public function testTwigFunctions(): void
    {
        $twig = self::getContainer()->get(MfaExtension::class);
        self::assertInstanceOf(MfaExtension::class, $twig);
        self::assertNull($twig->status());
        self::assertNull($twig->notice());
        self::assertNull($twig->accountPath());
        self::assertSame('default', $twig->status($this->user('admin@example.com'))?->profile);
        self::assertCount(3, $twig->getFunctions());

        $this->signIn('admin@example.com', 'admin-password-123');
        $crawler = $this->client->request('GET', '/mfa-card');
        self::assertSelectorExists('[data-testid="auth-kit-mfa-card"]');
        self::assertStringContainsString('Required for your account', $crawler->text());
    }
}
