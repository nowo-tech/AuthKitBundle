<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Real-kernel passkey tests (2.4.0) on the MFA kernel: helpers to register a {@see SoftwareAuthenticator} through the
 * account page and to sign in with it from the login page, the way the bundled script does (options JSON, then the
 * CSRF form with the hidden credential).
 */
abstract class WebauthnKernelTestCase extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['WebauthnCredentialAddedEvent', 'WebauthnCredentialUsedEvent', 'WebauthnCredentialRemovedEvent', 'WebauthnAuthenticationFailedEvent'] as $event) {
            $dispatcher->addListener('Nowo\\AuthKitBundle\\Webauthn\\Event\\' . $event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    protected function passkeys(): WebauthnManager
    {
        $manager = self::getContainer()->get(WebauthnManager::class);
        self::assertInstanceOf(WebauthnManager::class, $manager);

        return $manager;
    }

    /**
     * Signs in with the password and follows the redirect.
     */
    protected function signedIn(string $email, string $password, ?KernelBrowser $client = null): void
    {
        $client ??= $this->client;
        $this->signIn($email, $password, false, $client);
        $client->followRedirect();
    }

    /**
     * The JSON a GET answers (asserting its status).
     */
    protected function json(string $url, int $status = 200, ?KernelBrowser $client = null): string
    {
        $client ??= $this->client;
        $client->request('GET', $url, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        return (string) $client->getResponse()->getContent();
    }

    /**
     * Registers a passkey through the account page (options, then the form); returns the page after the redirect.
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, fmt?: string, type?: string, challenge?: string} $tweaks
     */
    protected function registerPasskey(SoftwareAuthenticator $authenticator, string $label = 'Laptop', array $tweaks = [], ?KernelBrowser $client = null): Crawler
    {
        $client ??= $this->client;
        $page = $client->request('GET', '/account/webauthn');
        self::assertResponseIsSuccessful();
        $options = $this->json('/account/webauthn/register/options', 200, $client);
        $form    = $page->filter('form[name="webauthn_register"]')->form([
            'webauthn_register[label]'      => $label,
            'webauthn_register[credential]' => $authenticator->register($options, 'http://localhost', $tweaks),
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/account/webauthn');

        return $client->followRedirect();
    }

    /**
     * Signs in with a passkey from the login page (no redirect followed).
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, user_handle?: string|null, type?: string, challenge?: string, counter?: int} $tweaks
     */
    protected function passkeyLogin(SoftwareAuthenticator $authenticator, array $tweaks = [], ?KernelBrowser $client = null): void
    {
        $client ??= $this->client;
        $page = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $options = $this->json('/login/webauthn/options', 200, $client);
        $form    = $page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $authenticator->assert($options, 'http://localhost', $tweaks)]);
        $client->submit($form);
    }

    /**
     * The identifier and token class `/mfa-home` reports (`anonymous|<location>` when it redirects).
     */
    protected function whoAmI(?KernelBrowser $client = null): string
    {
        $client ??= $this->client;
        $client->request('GET', '/mfa-home');

        return $client->getResponse()->isRedirect() ? 'anonymous|' . $client->getResponse()->headers->get('Location') : (string) $client->getResponse()->getContent();
    }
}
