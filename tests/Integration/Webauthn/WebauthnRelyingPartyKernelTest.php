<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\RelyingPartySettings;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function json_decode;

/**
 * 2.6.0: a host relying party provider (an instance setting, {@see RelyingPartySettings}) drives every ceremony, per
 * request; invalid values fail closed (no options, no verification, "use your password"); a passkey or a challenge of
 * another RP id is refused with its own reason; the passkeys a new RP id would orphan can be counted.
 */
final class WebauthnRelyingPartyKernelTest extends WebauthnKernelTestCase
{
    private const MEMBER = 'member@example.com';

    private const PASSWORD = 'member-password-123';

    protected static function getKernelClass(): string
    {
        return WebauthnProviderTestKernel::class;
    }

    #[Test]
    public function theProviderOverrideDrivesRegistrationAndSignInAndTheCredentialKeepsItsRpId(): void
    {
        $passkey = $this->registerOn('example.com', ['https://example.com']);
        self::assertSame(['example.com'], $this->storedRpIds());

        $this->client->getCookieJar()->clear();
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        self::assertSame('example.com', $this->decode($options)['rpId']);
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertCount(1, $this->eventsOf(WebauthnCredentialUsedEvent::class));
        self::assertStringStartsWith(self::MEMBER . '|', $this->whoAmI());

        $manager = $this->passkeys();
        self::assertSame(1, $manager->countCredentialsForRpId('example.com'));
        self::assertSame(1, $manager->countCredentialsForRpId('EXAMPLE.com', 'default'));
        self::assertSame(0, $manager->countCredentialsForRpId('example.com', 'another-profile'));
        self::assertSame(0, $manager->countCredentialsOutsideRpId('example.com'));
        self::assertSame(1, $manager->countCredentialsOutsideRpId('login.example.com', 'default'));
    }

    #[Test]
    public function aStoredRpIdWithDefaultOriginsNeverTrustsAForgedHost(): void
    {
        // Audit ZCQ-M1: the host stores only the RP id; webauthn.rp_id is null and the origins come from the default.
        $passkey = $this->registerOn('example.com', null);
        $this->client->getCookieJar()->clear();
        $this->events = [];

        // The attacker's sub-domain page got the victim's assertion for rpId example.com and posts it with a forged Host.
        $this->client->setServerParameters(['HTTP_HOST' => 'evil.example.com', 'HTTPS' => 'on']);
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        self::assertSame('example.com', $this->decode($options)['rpId']);
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://evil.example.com')]));
        self::assertSame([WebauthnFailure::INVALID], $this->reasons());
        self::assertStringStartsWith('anonymous|', $this->whoAmI());

        // The real origin signs in.
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameters(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']);
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertStringStartsWith(self::MEMBER . '|', $this->whoAmI());
    }

    #[Test]
    public function anOriginOfAnotherDomainIsDroppedAndPasskeysKeepWorking(): void
    {
        // Audit ZCQ-M2 (BC with 2.5): example.org cannot serve example.com; it is ignored, example.com still works.
        $passkey = $this->registerOn('example.com', ['https://example.com', 'https://example.org']);
        $this->client->getCookieJar()->clear();
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertStringStartsWith(self::MEMBER . '|', $this->whoAmI());
    }

    #[Test]
    public function oneListOfOriginsServesSeveralDomainsWithTheRequestHostAsRpId(): void
    {
        // 2.5 multi-domain setup: rp_id null (the request host), both domains listed; each keeps its own origin.
        $this->settings()->rpId    = null;
        $this->settings()->origins = ['https://example.com', 'https://example.org'];
        foreach (['example.com', 'example.org'] as $host) {
            $this->client->setServerParameters(['HTTP_HOST' => $host, 'HTTPS' => 'on']);
            self::assertSame($host, $this->decode($this->json('/login/webauthn/options'))['rpId']);
        }
    }

    #[Test]
    public function aPasskeyOfAnotherRpIdIsRefusedWithItsOwnReason(): void
    {
        $passkey = $this->registerOn('example.com', ['https://example.com']);
        $this->client->getCookieJar()->clear();
        $this->events = [];

        // The administrator moves the RP id to a sub-domain: the passkey of example.com no longer works there.
        $this->onHost('login.example.com', ['https://login.example.com']);
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        self::assertSame('login.example.com', $this->decode($options)['rpId']);
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://login.example.com')]));
        self::assertResponseRedirects();
        self::assertSame([WebauthnFailure::RP_ID_MISMATCH], $this->reasons());
        self::assertSame([], $this->eventsOf(WebauthnCredentialUsedEvent::class));
        self::assertStringStartsWith('anonymous|', $this->whoAmI());
        self::assertSame(1, $this->passkeys()->countCredentialsOutsideRpId('login.example.com'));
    }

    #[Test]
    public function aPasskeyCreatedBeforeTheColumnStillSignsIn(): void
    {
        $passkey = $this->registerOn('example.com', ['https://example.com']);
        // Audit ZCQ-L1: a backfill in another case still matches.
        $this->em()->getConnection()->executeStatement('UPDATE ' . WebauthnCredential::TABLE . " SET rp_id = 'Example.COM'");
        self::assertSame(1, $this->passkeys()->countCredentialsForRpId('example.com'));
        $this->em()->getConnection()->executeStatement('UPDATE ' . WebauthnCredential::TABLE . ' SET rp_id = NULL');
        self::assertSame(0, $this->passkeys()->countCredentialsForRpId('example.com'));
        self::assertSame(1, $this->passkeys()->countCredentialsOutsideRpId('example.com'));

        $this->client->getCookieJar()->clear();
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertStringStartsWith(self::MEMBER . '|', $this->whoAmI());
    }

    #[Test]
    public function aChallengeIssuedForTheFormerRpIdIsRefused(): void
    {
        $passkey = $this->registerOn('example.com', ['https://example.com']);
        $this->client->getCookieJar()->clear();
        $this->events = [];

        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        // The RP id changes between the options and the answer.
        $this->settings()->rpId    = 'login.example.com';
        $this->settings()->origins = ['https://login.example.com'];
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertSame([WebauthnFailure::RP_ID_MISMATCH], $this->reasons());
        self::assertStringStartsWith('anonymous|', $this->whoAmI());
    }

    #[Test]
    public function aRegistrationStartedForTheFormerRpIdIsRefused(): void
    {
        $this->onHost('example.com', ['https://example.com']);
        $this->signedIn(self::MEMBER, self::PASSWORD);
        $page                      = $this->client->request('GET', '/account/webauthn');
        $options                   = $this->json('/account/webauthn/register/options');
        $this->settings()->rpId    = 'login.example.com';
        $this->settings()->origins = ['https://login.example.com'];
        $this->client->submit($page->filter('form[name="webauthn_register"]')->form(['webauthn_register[credential]' => (new SoftwareAuthenticator())->register($options, 'https://example.com')]));
        self::assertResponseRedirects('/account/webauthn');
        self::assertSame([WebauthnFailure::RP_ID_MISMATCH], $this->reasons());
        self::assertSame([], $this->storedRpIds());
    }

    /**
     * @return iterable<string, array{string|null, list<string>|null}>
     */
    public static function invalidRelyingParties(): iterable
    {
        yield 'scheme in the RP id' => ['https://example.com', ['https://example.com']];
        yield 'port in the RP id' => ['example.com:443', ['https://example.com']];
        yield 'IP literal' => ['127.0.0.1', ['https://127.0.0.1']];
        yield 'single label' => ['com', ['https://example.com']];
        yield 'only an origin on another domain' => ['example.com', ['https://example.org']];
        yield 'origin on a look-alike domain' => ['example.com', ['https://evilexample.com']];
        yield 'plain http origin' => ['example.com', ['http://example.com']];
        yield 'origin with a path' => ['example.com', ['https://example.com/login']];
        yield 'no origin' => ['example.com', []];
    }

    /**
     * @param list<string> $origins
     */
    #[Test]
    #[DataProvider('invalidRelyingParties')]
    public function anInvalidRelyingPartyIssuesNoOptions(string $rpId, array $origins): void
    {
        $this->onHost('example.com', $origins, $rpId);
        $page = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Passkeys are not available right now. Sign in with your password.', (string) $page->filter('[data-nowo-auth-kit-webauthn="login"]')->attr('data-label-unavailable'));
        self::assertSame(['error' => 'unavailable'], $this->decode($this->json('/login/webauthn/options', 503)));
    }

    #[Test]
    public function aFailingProviderIssuesNoOptions(): void
    {
        $this->onHost('example.com', ['https://example.com']);
        $this->settings()->down = true;
        self::assertSame(['error' => 'unavailable'], $this->decode($this->json('/login/webauthn/options', 503)));
    }

    #[Test]
    public function anAssertionIsNeverVerifiedOnceTheRelyingPartyIsInvalid(): void
    {
        $passkey = $this->registerOn('example.com', ['https://example.com']);
        $this->client->getCookieJar()->clear();
        $this->events = [];

        $page                      = $this->client->request('GET', '/login');
        $options                   = $this->json('/login/webauthn/options');
        $this->settings()->origins = ['https://example.org'];
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $passkey->assert($options, 'https://example.com')]));
        self::assertResponseRedirects();
        self::assertStringContainsString('Passkeys are not available right now', $this->client->followRedirect()->text());
        self::assertSame([WebauthnFailure::RELYING_PARTY], $this->reasons());
        self::assertStringStartsWith('anonymous|', $this->whoAmI());
    }

    #[Test]
    public function theAccountPageStaysUsableButRegistersNothingWhileTheRelyingPartyIsInvalid(): void
    {
        $this->onHost('example.com', ['https://example.com']);
        $this->signedIn(self::MEMBER, self::PASSWORD);
        $page    = $this->client->request('GET', '/account/webauthn');
        $options = $this->json('/account/webauthn/register/options');

        $this->settings()->rpId = 'https://example.com';
        $account                = $this->client->request('GET', '/account/webauthn');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $account->filter('[data-nowo-auth-kit-webauthn-signal]'));
        self::assertSame(['error' => 'unavailable'], $this->decode($this->json('/account/webauthn/register/options', 503)));

        $this->client->submit($page->filter('form[name="webauthn_register"]')->form(['webauthn_register[credential]' => (new SoftwareAuthenticator())->register($options, 'https://example.com')]));
        self::assertResponseRedirects('/account/webauthn');
        self::assertStringContainsString('Passkeys are not available right now', $this->client->followRedirect()->text());
        self::assertSame([], $this->storedRpIds());
    }

    /**
     * Registers a passkey for the member on `https://<rp id>` with the settings' RP id and origins (null: the default).
     *
     * @param list<string>|null $origins
     */
    private function registerOn(string $rpId, ?array $origins): SoftwareAuthenticator
    {
        $this->onHost($rpId, $origins);
        $this->signedIn(self::MEMBER, self::PASSWORD);
        $passkey = new SoftwareAuthenticator();
        $page    = $this->client->request('GET', '/account/webauthn');
        self::assertCount(1, $page->filter('[data-nowo-auth-kit-webauthn-signal]'));
        $options = $this->json('/account/webauthn/register/options');
        self::assertStringContainsString('"rp":{"id":"' . $rpId . '"', $options);
        $this->client->submit($page->filter('form[name="webauthn_register"]')->form(['webauthn_register[credential]' => $passkey->register($options, 'https://' . $rpId)]));
        self::assertResponseRedirects('/account/webauthn');
        self::assertCount(1, $this->passkeys()->passkeys($this->user(self::MEMBER)));

        return $passkey;
    }

    /**
     * The browser on `https://<host>`, the settings on that RP id (or `$rpId`) and origins (null: the default).
     *
     * @param list<string>|null $origins
     */
    private function onHost(string $host, ?array $origins, ?string $rpId = null): void
    {
        $this->client->setServerParameters(['HTTP_HOST' => $host, 'HTTPS' => 'on']);
        $this->settings()->rpId    = $rpId ?? $host;
        $this->settings()->origins = $origins;
        $this->settings()->down    = false;
    }

    private function settings(): RelyingPartySettings
    {
        $settings = self::getContainer()->get(RelyingPartySettings::class);
        self::assertInstanceOf(RelyingPartySettings::class, $settings);

        return $settings;
    }

    /**
     * @return list<string|null>
     */
    private function storedRpIds(): array
    {
        /** @var list<string|null> $rpIds */
        $rpIds = $this->em()->getConnection()->fetchFirstColumn('SELECT rp_id FROM ' . WebauthnCredential::TABLE);

        return $rpIds;
    }

    /**
     * @return list<string>
     */
    private function reasons(): array
    {
        return array_map(static fn (WebauthnAuthenticationFailedEvent $event): string => $event->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }
}
