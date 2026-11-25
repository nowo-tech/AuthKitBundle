<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Oidc;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialIssuerGuard;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeResolver;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function json_encode;
use function parse_str;
use function parse_url;
use function serialize;
use function str_contains;
use function str_repeat;
use function time;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * 2.3.0 OpenID Connect providers through the real kernel, against a fake provider: the generic issuer, GitLab
 * (self-hosted), LinkedIn, Slack and Apple (form_post relay), and every refusal the callback can end in.
 */
final class OidcSignInKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return OidcTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        foreach ([OidcTestKernel::POOL, 'cache.app'] as $id) {
            $pool = self::getContainer()->get($id);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }

        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->persist($this->credential('okta')->setIssuer('https://idp.example.com'));
        $em->persist($this->credential('gitlab')->setIssuer('https://git.example.org'));
        $em->persist($this->credential('linkedin'));
        $em->persist($this->credential('slack'));
        $em->persist($this->credential('apple')->setClientId('com.example.web')->setClientSecret(TestKeys::pem(TestKeys::ec()))->setProviderSettings(['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567']));
        $em->persist($this->credential('google'));
        $em->flush();
        $em->clear();
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function providers(): iterable
    {
        yield 'generic issuer' => ['okta', 'https://idp.example.com', true];
        yield 'self-hosted gitlab' => ['gitlab', 'https://git.example.org', true];
        yield 'linkedin' => ['linkedin', OidcProviderCatalog::LINKEDIN_ISSUER, false];
        yield 'slack' => ['slack', OidcProviderCatalog::SLACK_ISSUER, true];
    }

    #[DataProvider('providers')]
    public function testSignInLinksTheVerifiedAccount(string $provider, string $issuer, bool $nonce): void
    {
        $idp   = $this->idp($issuer);
        $query = $this->start($provider);
        self::assertStringStartsWith($issuer . '/authorize?', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame($nonce, isset($query['nonce']));
        $idp->nonce = $query['nonce'] ?? null;

        $this->client->request('GET', '/login/social/' . $provider . '/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/home');
        self::assertSame('ada@example.com', $this->tokens()->getToken()?->getUserIdentifier());
        $link = $this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => $provider, 'providerUserId' => 'subject-1']);
        self::assertInstanceOf(SocialLoginAccount::class, $link);
        self::assertSame('Ada Lovelace', $link->getDisplayName());
        $this->assertNothingSecretLogged();
    }

    public function testSignInWithAppleThroughTheFormPostRelay(): void
    {
        $idp           = $this->idp(OidcProviderCatalog::APPLE_ISSUER, 'com.example.web');
        $idp->userinfo = null;
        $idp->claims   = ['name' => null, 'email_verified' => 'true'];
        $query         = $this->start('apple');
        self::assertSame('form_post', $query['response_mode']);
        $idp->nonce = $query['nonce'];

        // Apple's cross-site POST: the session cookie (SameSite=Lax) does not come with it.
        $cookies = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $fields  = ['code' => 'code-1', 'state' => $query['state'], 'user' => json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace']], JSON_THROW_ON_ERROR), 'id_token' => 'front-channel-token'];
        $crawler = $this->client->request('POST', '/login/social/apple/check', $fields, [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame([], $response->headers->getCookies(), 'The relay starts no session (it would replace the visitor\'s cookie).');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('js/nowo-auth-kit-form-post.js', (string) $response->getContent());
        $form = $crawler->filter('form[data-nowo-auth-kit-form-post="1"]')->form();
        self::assertSame('/login/social/apple/check', parse_url($form->getUri(), PHP_URL_PATH));
        $values = $form->getValues();
        self::assertSame('code-1', $values['code']);
        self::assertSame($query['state'], $values['state']);
        self::assertSame('1', $values[SocialFormPostRelay::MARKER]);
        self::assertArrayNotHasKey('id_token', $values, 'The front-channel ID token is not relayed.');
        self::assertSame(0, $this->tokenRequests($idp));

        // The relayed same-origin POST carries the session cookie again.
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
        $this->client->submit($form);

        self::assertResponseRedirects('/home');
        self::assertSame('ada@example.com', $this->tokens()->getToken()?->getUserIdentifier());
        $link = $this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'apple']);
        self::assertInstanceOf(SocialLoginAccount::class, $link);
        self::assertSame('Ada Lovelace', $link->getDisplayName(), 'The name of the first sign-in is kept.');
        $this->assertNothingSecretLogged();
    }

    public function testAppleCancellationComesBackThroughTheRelay(): void
    {
        $this->idp(OidcProviderCatalog::APPLE_ISSUER, 'com.example.web');
        $query = $this->start('apple');

        $this->client->request('POST', '/login/social/apple/check', ['error' => 'user_cancelled_authorize', 'state' => $query['state'], SocialFormPostRelay::MARKER => '1'], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('cancelled or denied', (string) $this->client->getResponse()->getContent());
    }

    public function testRelayEdgeCases(): void
    {
        // A relayed POST the browser says comes from another site is relayed again, never processed.
        $this->client->request('POST', '/login/social/apple/check', ['code' => 'c', 'state' => 's', SocialFormPostRelay::MARKER => '1'], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-nowo-auth-kit-form-post="1"]');

        // Field values over the size cap are dropped.
        $this->client->request('POST', '/login/social/apple/check', ['code' => str_repeat('a', 5000), 'state' => 's']);
        self::assertArrayNotHasKey('code', $this->client->getCrawler()->filter('form[data-nowo-auth-kit-form-post="1"]')->form()->getValues());

        // Providers that do not use form_post: no relay, no processing.
        $this->client->request('POST', '/login/social/google/check', ['code' => 'c', 'state' => 's']);
        self::assertResponseRedirects('/login');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $this->client->request('POST', '/login/social/google/check', ['code' => 'c', 'state' => 's', SocialFormPostRelay::MARKER => '1']);
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/login/social/unknown/check', ['code' => 'c']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->tokenRequests($this->idp('https://idp.example.com')));
    }

    /**
     * @return iterable<string, array{callable(FakeIdp, FakeResolver): void}>
     */
    public static function refusals(): iterable
    {
        yield 'signature by another key' => [static function (FakeIdp $idp): void { $idp->jwks = ['keys' => [TestKeys::jwk(TestKeys::rsa('attacker'), 'key-1')]]; }];
        yield 'another issuer in the token' => [static function (FakeIdp $idp): void { $idp->claims = ['iss' => 'https://evil.example.com']; }];
        yield 'another audience' => [static function (FakeIdp $idp): void { $idp->claims = ['aud' => 'other-client']; }];
        yield 'expired token' => [static function (FakeIdp $idp): void { $idp->claims = ['exp' => time() - 3600, 'iat' => time() - 4000]; }];
        yield 'nonce of another request' => [static function (FakeIdp $idp): void { $idp->claims = ['nonce' => 'replayed']; }];
        yield 'alg none' => [static function (FakeIdp $idp): void { $idp->header = ['alg' => 'none']; }];
        yield 'HS256' => [static function (FakeIdp $idp): void { $idp->header = ['alg' => 'HS256']; }];
        yield 'token endpoint refuses' => [static function (FakeIdp $idp): void { $idp->tokenStatus = 400; }];
        yield 'userinfo about someone else' => [static function (FakeIdp $idp): void { $idp->userinfo = ['sub' => 'subject-2']; }];
        yield 'jwks on a private address' => [static function (FakeIdp $idp, FakeResolver $resolver): void {
            $resolver->answers['keys.example.com'] = ['10.0.0.7'];
            $idp->discovery                        = ['jwks_uri' => 'https://keys.example.com/jwks'];
        }];
    }

    /**
     * @param callable(FakeIdp, FakeResolver): void $bend
     */
    #[DataProvider('refusals')]
    public function testRefusedCallbacksSignNobodyIn(callable $bend): void
    {
        $idp        = $this->idp('https://idp.example.com');
        $query      = $this->start('okta');
        $idp->nonce = $query['nonce'];
        $bend($idp, $this->resolver());

        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertNull($this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'okta']));
        $this->client->followRedirect();
        self::assertStringContainsString('Social login failed', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->logged('reason'), 'The failure is logged with its reason.');
        $this->assertNothingSecretLogged();
    }

    public function testALinkMadeUnderAnotherIssuerNeverSignsIn(): void
    {
        // ZBP-L1: the okta credential first points at idp.example.com; Ada links with sub "subject-1".
        $idp        = $this->idp('https://idp.example.com');
        $query      = $this->start('okta');
        $idp->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/home');
        $link = $this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'okta']);
        self::assertInstanceOf(SocialLoginAccount::class, $link);
        self::assertSame('https://idp.example.com', $link->getIssuer());
        $this->client->request('GET', '/logout');
        $this->em()->clear();

        // The admin moves the key to another IdP: its "subject-1" is somebody else and must not get Ada's account.
        $credential = $this->em()->getRepository(SocialLoginCredential::class)->findOneBy(['provider' => 'okta']);
        self::assertInstanceOf(SocialLoginCredential::class, $credential);
        $guard = self::getContainer()->get('test.issuer_guard');
        self::assertInstanceOf(SocialCredentialIssuerGuard::class, $guard);
        self::assertNotNull($guard->issuerChangeError($credential, 'https://other-idp.example.com'), 'The admin path is told first.');
        $credential->setIssuer('https://other-idp.example.com');
        $this->em()->flush();
        $this->em()->clear();

        // Same sub, even the same verified e-mail: the stale link is refused, never followed (it would sign in Ada).
        $idp        = $this->idp('https://other-idp.example.com');
        $query      = $this->start('okta');
        $idp->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertTrue($this->logged('RuntimeException'));
    }

    public function testStartsAreRateLimitedPerClient(): void
    {
        $this->idp('https://idp.example.com');
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('GET', '/login/social/okta');
            self::assertStringStartsWith('https://idp.example.com/authorize?', (string) $this->client->getResponse()->headers->get('Location'));
        }
        $this->client->request('GET', '/login/social/okta');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('Too many sign-in attempts', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnverifiedEmailIsNeverLinked(): void
    {
        $idp         = $this->idp('https://idp.example.com');
        $query       = $this->start('okta');
        $idp->nonce  = $query['nonce'];
        $idp->claims = ['email_verified' => false];

        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/login');
        self::assertNull($this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'okta']));
    }

    public function testAProviderThatCannotBeDiscoveredFailsAtTheStart(): void
    {
        $this->idp('https://idp.example.com');
        $this->resolver()->answers['idp.example.com'] = ['127.0.0.1'];

        $this->client->request('GET', '/login/social/okta');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('Social login failed', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->logged('endpoint_not_allowed'));
    }

    public function testTheCallbackOfAnotherAuthorizationIsRefused(): void
    {
        $idp        = $this->idp('https://idp.example.com');
        $query      = $this->start('okta');
        $idp->nonce = $query['nonce'];

        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => 'forged']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->tokenRequests($idp));
    }

    private function idp(string $issuer, string $clientId = 'client-placeholder'): FakeIdp
    {
        $idp = self::getContainer()->get(FakeIdp::class);
        self::assertInstanceOf(FakeIdp::class, $idp);
        $idp->issuer   = $issuer;
        $idp->clientId = $clientId;

        return $idp;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function resolver(): FakeResolver
    {
        $resolver = self::getContainer()->get(FakeResolver::class);
        self::assertInstanceOf(FakeResolver::class, $resolver);

        return $resolver;
    }

    /**
     * @return array<string, string>
     */
    private function start(string $provider): array
    {
        $this->client->request('GET', '/login/social/' . $provider);
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        /** @var array<string, string> $typed */
        $typed = $query;

        return $typed;
    }

    private function tokenRequests(FakeIdp $idp): int
    {
        $count = 0;
        foreach ($idp->requests as $request) {
            if (str_contains($request['url'], '/token')) {
                ++$count;
            }
        }

        return $count;
    }

    private function logged(string $needle): bool
    {
        foreach ($this->logger()->records as [, $context]) {
            if (str_contains(serialize($context), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function assertNothingSecretLogged(): void
    {
        foreach ($this->logger()->records as [$message, $context]) {
            if (!str_contains($message, 'AuthKit')) {
                continue; // the framework's and Doctrine's own debug log of the test kernel, not AuthKit's
            }
            $text = $message . serialize($context);
            foreach (['secret-placeholder', 'at-placeholder', 'BEGIN PRIVATE KEY', 'code-1', 'eyJ'] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
    }

    private function logger(): RecordingLogger
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    private function credential(string $provider): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider($provider)->setLabel($provider)->setEnabled(true)->setClientId('client-placeholder')->setClientSecret('secret-placeholder');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
