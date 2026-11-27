<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Oidc\OidcTestKernel;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function explode;
use function parse_str;
use function parse_url;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;

use const PHP_URL_QUERY;

/**
 * 2.7.0 enterprise OpenID Connect (Beacon spec 221 blocks 0 + A) through the real kernel: unlisted credentials,
 * `login_hint`, the per-credential `link_existing_account` and the host-minted test sign-in.
 */
final class EnterpriseOidcKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return EnterpriseOidcTestKernel::class;
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
        $em->persist($this->credential('org-acme')->setIssuer('https://idp.example.com')->setEnterpriseSso(true)->setListed(false));
        $em->persist($this->credential('okta')->setIssuer('https://idp.example.com')->setEnterpriseSso(true));
        $em->persist($this->credential('google'));
        $em->flush();
        $em->clear();
        $this->idp();
        $this->handler()->handled  = [];
        $this->handler()->failures = [];
        $this->handler()->signIn   = null;
    }

    public function testAnUnlistedCredentialIsNoButtonButStartsByNameWithItsLoginHint(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/login/social/okta', $page);
        self::assertStringContainsString('/login/social/google', $page);
        self::assertStringNotContainsString('/login/social/org-acme', $page);

        $query = $this->start('/login/social/org-acme?login_hint=ada%40example.com');
        self::assertSame('ada@example.com', $query['login_hint']);
    }

    public function testAnInvalidLoginHintIsDropped(): void
    {
        foreach (['not-an-email', 'ada@example.com%0Aevil', str_repeat('a', 250) . '%40example.com', ''] as $hint) {
            self::assertArrayNotHasKey('login_hint', $this->start('/login/social/okta?login_hint=' . $hint), $hint);
        }
        self::assertArrayNotHasKey('login_hint', $this->start('/login/social/okta?login_hint[]=ada%40example.com'));
    }

    public function testATestSignInGivesTheValidatedClaimsToTheHostAndSignsNobodyIn(): void
    {
        $idp           = $this->idp();
        $idp->claims   = ['amr' => ['pwd', 'mfa'], 'tid' => 'tenant-1'];
        $idp->userinfo = ['amr' => ['hwk'], 'department' => 'R&D'];
        // A disabled credential can be tested before it is enabled.
        $this->disable('org-acme');

        $query = $this->start('/admin/sso-test/org-acme?login_hint=ada%40example.com');
        self::assertSame('ada@example.com', $query['login_hint']);
        $idp->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/org-acme/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/admin/sso-test-result');
        self::assertNull($this->tokens()->getToken());
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        self::assertCount(1, $this->handler()->handled);
        $profile = $this->handler()->handled[0]->socialProfile;
        self::assertSame('subject-1', $profile->id);
        self::assertSame(['pwd', 'mfa'], $profile->idTokenClaims['amr'] ?? null, 'The amr of the ID token, never the userinfo one.');
        self::assertSame('tenant-1', $profile->idTokenClaims['tid'] ?? null);
        self::assertSame('ada@example.com', $profile->idTokenClaims['email'] ?? null);
        self::assertTrue($profile->idTokenClaims['email_verified'] ?? null);
        self::assertArrayNotHasKey('nonce', $profile->idTokenClaims ?? []);
        self::assertArrayNotHasKey('department', $profile->idTokenClaims ?? [], 'Userinfo never reaches the ID token claims.');

        // One shot: the same state again is refused and reaches no handler.
        $this->client->request('GET', '/login/social/org-acme/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/login');
        self::assertCount(1, $this->handler()->handled);
        self::assertNull($this->tokens()->getToken());
    }

    public function testAUrlParameterNeverMakesATest(): void
    {
        $query              = $this->start('/login/social/okta?test=1&mode=test');
        $this->idp()->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state'], 'test' => '1']);

        self::assertResponseRedirects('/home');
        self::assertSame('ada@example.com', $this->tokens()->getToken()?->getUserIdentifier());
        self::assertSame([], $this->handler()->handled);
    }

    public function testATestHandlerAskingForASignInSignsNobodyIn(): void
    {
        $this->handler()->signIn = AuthUserRef::ofId(KernelUser::class, '1', 'ada@example.com');
        $query                   = $this->start('/admin/sso-test/okta');
        $this->idp()->nonce      = $query['nonce'];
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertCount(1, $this->handler()->handled);
        self::assertSame([SocialCallbackFailure::Failed], $this->handler()->failures);
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
    }

    public function testAFailedTestReachesTheTestHandler(): void
    {
        $query = $this->start('/admin/sso-test/okta');
        $this->client->request('GET', '/login/social/okta/check', ['error' => 'access_denied', 'state' => $query['state']]);
        self::assertResponseRedirects('/admin/sso-test-failed');
        self::assertSame([SocialCallbackFailure::ProviderDenied], $this->handler()->failures);

        $idp              = $this->idp();
        $idp->tokenStatus = 400;
        $query            = $this->start('/admin/sso-test/okta');
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/admin/sso-test-failed');
        self::assertSame([SocialCallbackFailure::ProviderDenied, SocialCallbackFailure::Failed], $this->handler()->failures);
        self::assertSame([], $this->handler()->handled);
        self::assertNull($this->tokens()->getToken());
    }

    public function testATestOfACredentialRemovedMeanwhileTakesTheFailurePath(): void
    {
        $query = $this->start('/admin/sso-test/okta');
        $em    = $this->em();
        $em->remove($this->find('okta'));
        $em->flush();

        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/admin/sso-test-failed');
        self::assertSame([SocialCallbackFailure::Unavailable], $this->handler()->failures);
    }

    public function testTestStartRefusals(): void
    {
        $this->client->request('GET', '/admin/sso-test/unknown');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('unknown_provider', $this->client->getResponse()->getContent());

        $this->idp()->discoveryStatus = 500;
        $this->client->request('GET', '/admin/sso-test/okta');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('provider', $this->client->getResponse()->getContent());

        $tests = self::getContainer()->get('test.social_test_start');
        self::assertInstanceOf(SocialLoginTestStart::class, $tests);
        self::assertTrue($tests->hasHandler());
    }

    public function testACredentialDenyNeverLinksAnExistingAccountByEmail(): void
    {
        $em = $this->em();
        $this->find('okta')->setLinkExistingAccount('deny');
        $em->flush();

        $query              = $this->start('/login/social/okta');
        $this->idp()->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/okta/check', ['code' => 'code-1', 'state' => $query['state']]);

        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        $user = $this->em()->getRepository(KernelUser::class)->findOneBy(['email' => 'ada@example.com']);
        self::assertInstanceOf(KernelUser::class, $user);
        self::assertSame('old-password-123', $user->getPassword(), 'Refused before any password reset.');
    }

    /**
     * 2.8.0: a sign-in with an RP-logout credential sends the sign-out on to the provider's end_session_endpoint, after
     * the local session is gone.
     */
    public function testASignOutAfterAnRpLogoutSignInGoesToTheProvider(): void
    {
        $this->find('okta')->setRpInitiatedLogout(true);
        $this->em()->flush();
        $this->idp()->discovery = ['end_session_endpoint' => 'https://idp.example.com/logout'];
        $this->signIn('okta');

        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertTrue(str_starts_with($location, 'https://idp.example.com/logout?'), $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('client-placeholder', $query['client_id'] ?? null);
        self::assertSame('http://localhost/', $query['post_logout_redirect_uri'] ?? null, 'The sign-out target, on the allow-list.');
        self::assertIsString($query['id_token_hint'] ?? null);
        self::assertCount(3, explode('.', $query['id_token_hint']));
        self::assertNull($this->tokens()->getToken(), 'Signed out locally.');

        // The hint went with the session: a second sign-out is a plain one.
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/');
    }

    public function testWithoutTheOptInOrAnEndpointTheSignOutStaysLocal(): void
    {
        $this->idp()->discovery = ['end_session_endpoint' => 'https://idp.example.com/logout'];
        $this->signIn('okta');
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/');

        $this->find('okta')->setRpInitiatedLogout(true);
        $this->em()->flush();
        $this->idp()->discovery = [];
        $this->clearPools();
        $this->signIn('okta');
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/');
    }

    /**
     * 2.8.0 (fail closed): the enterprise client secrets of this kernel are stored encrypted (doctrine-encrypt-bundle).
     */
    public function testTheEnterpriseClientSecretIsStoredEncrypted(): void
    {
        $stored = (string) $this->em()->getConnection()->fetchOne('SELECT client_secret FROM auth_kit_social_credential WHERE provider = ?', ['okta']);
        self::assertTrue(str_ends_with($stored, '<ENC>'), $stored);
        self::assertFalse(str_contains($stored, 'secret-placeholder'));
        self::assertSame('secret-placeholder', $this->find('okta')->getClientSecret());
    }

    private function signIn(string $provider): void
    {
        $query              = $this->start('/login/social/' . $provider);
        $this->idp()->nonce = $query['nonce'];
        $this->client->request('GET', '/login/social/' . $provider . '/check', ['code' => 'code-1', 'state' => $query['state']]);
        self::assertResponseRedirects('/home');
        self::assertSame('ada@example.com', $this->tokens()->getToken()?->getUserIdentifier());
    }

    private function clearPools(): void
    {
        foreach ([OidcTestKernel::POOL, 'cache.app'] as $id) {
            $pool = self::getContainer()->get($id);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }
    }

    /**
     * @return array<string, string>
     */
    private function start(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertTrue(str_contains($location, 'https://idp.example.com/authorize?'), $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        /** @var array<string, string> $typed */
        $typed = $query;

        return $typed;
    }

    private function idp(): FakeIdp
    {
        $idp = self::getContainer()->get(FakeIdp::class);
        self::assertInstanceOf(FakeIdp::class, $idp);
        $idp->issuer   = 'https://idp.example.com';
        $idp->clientId = 'client-placeholder';

        return $idp;
    }

    private function handler(): RecordingTestHandler
    {
        $handler = self::getContainer()->get(RecordingTestHandler::class);
        self::assertInstanceOf(RecordingTestHandler::class, $handler);

        return $handler;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function disable(string $provider): void
    {
        $this->find($provider)->setEnabled(false);
        $this->em()->flush();
    }

    private function find(string $provider): SocialLoginCredential
    {
        $credential = $this->em()->getRepository(SocialLoginCredential::class)->findOneBy(['provider' => $provider]);
        self::assertInstanceOf(SocialLoginCredential::class, $credential);

        return $credential;
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
