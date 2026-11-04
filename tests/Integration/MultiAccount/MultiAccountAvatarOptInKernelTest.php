<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Symfony\Component\DomCrawler\Crawler;

/**
 * 1.35 (audit EC-L1 / EC-I2): `chooser.other_account_avatars` shows the other accounts' pictures only while the browser
 * is signed in; an anonymous visitor of a shared browser sees initials on the sign-in page and on /accounts, and the
 * sign-in page with accounts is `no-store`.
 */
final class MultiAccountAvatarOptInKernelTest extends MultiAccountKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiAccountAvatarOptInTestKernel::class;
    }

    public function testOtherAccountPicturesOnlyWhileSignedIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');

        // Signed in: the opt-in shows the parked member's picture next to the active admin's.
        self::assertSame(['/avatars/admin@example.com.png', '/avatars/member@example.com.png'], $this->pictures($this->menu()));

        // The admin signs out (logout_scope: current): the member stays parked, the browser is anonymous.
        $this->client->request('GET', '/logout');
        $login = $this->client->request('GET', '/login');
        $box   = $login->filter('[data-testid="auth-kit-accounts"]');
        self::assertCount(1, $box, 'The parked member is listed on the sign-in page.');
        self::assertSame([], $this->pictures($box), 'No picture for an anonymous visitor (EC-L1).');
        self::assertSame([], $box->filter('.nowo-auth-kit-accounts__identifier')->each(static fn (Crawler $n): string => $n->text()), 'identifier_display: none');
        self::assertSame(['MA'], $box->filter('[data-testid="auth-kit-account-avatar"]')->each(static fn (Crawler $n): string => $n->text()), 'The presenter name is shown, so are its initials.');
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache, 'EC-I2');
        self::assertStringContainsString('private', $cache);

        $chooser = $this->client->request('GET', '/accounts');
        self::assertSame([], $this->pictures($chooser), 'No picture on the anonymous chooser either.');
        self::assertStringNotContainsString('/avatars/', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function pictures(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="auth-kit-account-avatar"] img')->each(static fn (Crawler $n): string => (string) $n->attr('src'));
    }
}
