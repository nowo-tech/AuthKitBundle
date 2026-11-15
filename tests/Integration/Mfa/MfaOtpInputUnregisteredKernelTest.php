<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Audit QV-L1: with the OtpInput package installed but its bundle not registered, the auth pages render (no 500 on the
 * missing asset package) and the MFA code is a plain text field that signs in.
 */
final class MfaOtpInputUnregisteredKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaOtpInputUnregisteredTestKernel::class;
    }

    public function testThePagesRenderAndTheCodeIsAPlainTextField(): void
    {
        foreach (['/login', '/register'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $scripts = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
            self::assertNotContains('/bundles/nowootpinput/otp-input.js', $scripts);
        }

        $this->signIn('member@example.com', 'member-password-123');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertResponseIsSuccessful();
        self::assertSame('text', $crawler->filter('input[name="mfa_enable[code]"]')->attr('type'));
        self::assertCount(0, $crawler->filter('[data-nowo-otp-digit]'));
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');

        $crawler = $this->client->request('GET', '/login/mfa');
        self::assertResponseIsSuccessful();
        self::assertSame('text', $crawler->filter('input[name="mfa_code_form[code]"]')->attr('type'));
        $this->challenge(['code' => $this->code($secret)]);
        self::assertResponseStatusCodeSame(303);
    }
}
