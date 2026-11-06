<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * AK-1: the account and recovery-code pages render the configured templates with the same variables and keep their
 * security headers; the bundled grid layout is reached only through the configuration key.
 */
final class MfaHostTemplatesKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaHostTemplatesTestKernel::class;
    }

    public function testHostTemplatesRenderWithTheSameVariablesAndStayUncached(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertResponseIsSuccessful();
        $nav = $crawler->filter('[data-testid="host-nav"]');
        self::assertSame('default', $nav->attr('data-profile'));
        self::assertSame('no', $nav->attr('data-enabled'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        [, $codes] = $this->enrol();
        $crawler   = $this->client->request('GET', '/account/security/mfa');
        self::assertSame('yes', $crawler->filter('[data-testid="host-nav"]')->attr('data-enabled'));

        $page = $this->client->submit($crawler->filter('form[name="mfa_recovery_codes"]')->form(['mfa_recovery_codes[recovery_code]' => $codes[0]]));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'), 'Codes shown once are never stored.');

        $nav = $page->filter('[data-testid="host-nav"]');
        self::assertSame('default', $nav->attr('data-profile'));
        self::assertSame('10', $nav->attr('data-count'));
        self::assertSame('/account/security/mfa', $nav->attr('data-back'));
    }

    public function testGridLayoutShowsTheWarningTheGridAndOneActionsRow(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [, $codes] = $this->enrol();
        $crawler   = $this->client->request('GET', '/account/security/mfa');

        $page = $this->client->submit($crawler->filter('form[name="mfa_recovery_codes"]')->form(['mfa_recovery_codes[recovery_code]' => $codes[0]]));

        self::assertCount(1, $page->filter('link[href*="nowo-auth-kit.css"]'), 'The admin layout does not load the bundle CSS by itself.');
        self::assertCount(1, $page->filter('[data-testid="auth-kit-mfa-codes-warning"][role="alert"]'));
        $fresh = $page->filter('ol.nowo-auth-kit-mfa__code-grid li code')->each(static fn (Crawler $code): string => $code->text());
        self::assertCount(10, $fresh);
        self::assertSame($fresh, $page->filter('[data-testid="auth-kit-mfa-codes"] li')->each(static fn (Crawler $li): string => $li->text()));
        $actions = $page->filter('.nowo-auth-kit-mfa__codes-actions a');
        self::assertCount(2, $actions);
        self::assertSame('/account/security/mfa', $page->filter('[data-testid="auth-kit-mfa-codes-done"]')->attr('href'));
        self::assertStringContainsString($fresh[0], rawurldecode((string) $page->filter('[data-testid="auth-kit-mfa-codes-download"]')->attr('href')));
    }
}
