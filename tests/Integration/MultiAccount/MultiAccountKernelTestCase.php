<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\VaultCookie;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_replace;

/**
 * Real-kernel tests of the account switcher (1.34): helpers on top of the MFA test case.
 */
abstract class MultiAccountKernelTestCase extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MultiAccountTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['AccountAddedEvent', 'AccountSwitchingEvent', 'AccountSwitchedEvent', 'AccountRemovedEvent'] as $event) {
            $dispatcher->addListener('Nowo\\AuthKitBundle\\MultiAccount\\Event\\' . $event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    protected function menu(): Crawler
    {
        $crawler = $this->client->request('GET', '/accounts-menu');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string> "identifier|state" of the switcher rows
     */
    protected function accounts(): array
    {
        return $this->menu()->filter('[data-testid="auth-kit-account"]')->each(
            static fn (Crawler $row): string => $row->filter('.nowo-auth-kit-accounts__name')->text() . '|' . $row->attr('data-state'),
        );
    }

    protected function add(): void
    {
        $crawler = $this->menu();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-account-add"]')->closest('form')?->form() ?? self::fail('No add form.'));
        self::assertResponseRedirects('/login', 303);
    }

    protected function switchTo(string $identifier): void
    {
        $crawler = $this->menu();
        $row     = $crawler->filter('[data-testid="auth-kit-account"]')->reduce(static fn (Crawler $r): bool => $r->filter('.nowo-auth-kit-accounts__name')->text() === $identifier);
        self::assertCount(1, $row, 'Account ' . $identifier . ' is in the switcher.');
        $this->client->submit($row->filter('form.nowo-auth-kit-accounts__switch')->form());
    }

    protected function signOutOf(string $identifier): void
    {
        $crawler = $this->menu();
        $row     = $crawler->filter('[data-testid="auth-kit-account"]')->reduce(static fn (Crawler $r): bool => $r->filter('.nowo-auth-kit-accounts__name')->text() === $identifier);
        $this->client->submit($row->filter('form.nowo-auth-kit-accounts__sign-out')->form());
    }

    protected function whoami(): string
    {
        $this->client->request('GET', '/mfa-home');

        return $this->client->getResponse()->isRedirect() ? 'anonymous|' : (string) $this->client->getResponse()->getContent();
    }

    protected function vaultCookie(): ?string
    {
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            if (str_replace('__Host-', '', $cookie->getName()) === 'nowo_auth_kit_accounts') {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * Turns MFA on for a user through the API (the code is computed on the mock clock).
     */
    protected function enableMfa(string $email): string
    {
        $secret = $this->manager()->newSecret();
        self::assertNotNull($this->manager()->enable($this->user($email), $secret, $this->code($secret)));
        $this->nextStep();

        return $secret;
    }

    protected function cookieName(): string
    {
        return VaultCookie::class;
    }
}
