<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderCatalog;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function base64_encode;
use function explode;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_repeat;
use function urlencode;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

final class OidcClientTest extends TestCase
{
    public function testBeginSendsStateNoncePkceAndKeepsThemServerSide(): void
    {
        $idp        = new FakeIdp();
        $stack      = new OidcStack($idp);
        $states     = $this->states();
        $credential = $this->generic()->setAuthorizationParams(['prompt' => 'login']);

        $begin = $stack->client->begin($credential, 'okta', 'https://app.example.com/login/social/okta/check', $states, [], ['okta' => ['acr_values' => 'mfa']]);
        $query = $this->query($begin['url']);

        self::assertStringStartsWith('https://idp.example.com/authorize?', $begin['url']);
        self::assertSame($begin['state'], $query['state']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('client-placeholder', $query['client_id']);
        self::assertSame('openid email profile', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('login', $query['prompt']);
        self::assertSame('mfa', $query['acr_values']);
        self::assertArrayNotHasKey('response_mode', $query);

        $pending = $states->consumePending('okta', $begin['state']);
        self::assertNotNull($pending);
        self::assertSame($query['nonce'], $pending['nonce']);
        self::assertSame($query['code_challenge'], Pkce::challenge((string) $pending['code_verifier']));
    }

    public function testPkceFollowsTheOverrideThenTheDiscoveryDocument(): void
    {
        $idp            = new FakeIdp();
        $idp->discovery = ['code_challenge_methods_supported' => ['plain'], 'authorization_endpoint' => 'https://idp.example.com/authorize?tenant=a'];
        $stack          = new OidcStack($idp);

        $url = $stack->client->begin($this->generic(), 'okta', 'https://app.example.com/cb', $this->states())['url'];
        self::assertStringStartsWith('https://idp.example.com/authorize?tenant=a&', $url);
        self::assertArrayNotHasKey('code_challenge', $this->query($url), 'Only S256 is ever used.');

        $url = $stack->client->begin($this->generic(), 'okta', 'https://app.example.com/cb', $this->states(), [' OKTA ' => true])['url'];
        self::assertArrayHasKey('code_challenge', $this->query($url));
    }

    public function testLinkedinSendsNoNonceAndApplePostsTheCallback(): void
    {
        $linkedin = new OidcStack(new FakeIdp(OidcProviderCatalog::LINKEDIN_ISSUER));
        $query    = $this->query($linkedin->client->begin($this->credential('linkedin'), 'linkedin', 'https://app.example.com/cb', $this->states())['url']);
        self::assertArrayNotHasKey('nonce', $query);
        self::assertArrayNotHasKey('code_challenge', $query, 'The preset turns PKCE off by default.');

        $apple = new OidcStack(new FakeIdp(OidcProviderCatalog::APPLE_ISSUER));
        $query = $this->query($apple->client->begin($this->apple(), 'apple', 'https://app.example.com/cb', $this->states())['url']);
        self::assertSame('form_post', $query['response_mode']);
        self::assertSame('name email', $query['scope']);
        self::assertArrayHasKey('nonce', $query);
        self::assertTrue($apple->client->usesFormPost($this->apple()));
        self::assertFalse($apple->client->usesFormPost($this->generic()));
        self::assertFalse($apple->client->usesFormPost($this->credential('apple')), 'An Apple credential without its settings is not one.');
        self::assertFalse($apple->client->usesFormPost($this->credential('google')));
        self::assertTrue($apple->client->supports($this->apple()));
    }

    public function testAuthenticateVerifiesTheIdTokenAndCompletesTheProfileFromUserinfo(): void
    {
        $idp           = new FakeIdp();
        $idp->nonce    = 'n-1';
        $idp->claims   = ['email' => null, 'email_verified' => null, 'name' => null];
        $idp->userinfo = ['email' => 'ada@example.com', 'email_verified' => 'true', 'given_name' => 'Ada', 'family_name' => 'Lovelace'];
        $stack         = new OidcStack($idp);

        [$tokens, $profile] = $stack->client->authenticate($this->generic(), 'code-1', 'https://app.example.com/cb', 'verifier-1', 'n-1');

        self::assertSame('at-placeholder', $tokens['access_token']);
        self::assertNull($tokens['refresh_token']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertSame('subject-1', $profile->id);
        self::assertSame('ada@example.com', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertSame('Ada Lovelace', $profile->name);
        self::assertArrayNotHasKey('nonce', $profile->raw);

        // Token request: the discovery document lists client_secret_post, so the secret is in the body.
        $token = $idp->requests[1];
        self::assertSame('POST', $token['method']);
        self::assertSame('https://idp.example.com/token', $token['url']);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://app.example.com/cb', 'client_id' => 'client-placeholder', 'code_verifier' => 'verifier-1', 'client_secret' => 'secret-placeholder'], $this->body($token['options']));
        self::assertContains('Authorization: Bearer at-placeholder', $idp->requests[3]['options']['headers'] ?? []);
    }

    public function testTheIdTokenEmailAndItsVerificationWinTogether(): void
    {
        $idp           = new FakeIdp();
        $idp->nonce    = 'n-1';
        $idp->claims   = ['email' => 'ada@example.com', 'email_verified' => null, 'name' => null, 'preferred_username' => 'ada'];
        $idp->userinfo = ['email' => 'other@example.com', 'email_verified' => true];

        [, $profile] = (new OidcStack($idp))->client->authenticate($this->generic(), 'c', 'https://app.example.com/cb', null, 'n-1');
        self::assertSame('ada@example.com', $profile->email);
        self::assertNull($profile->emailVerified, 'The userinfo flag is about another address: not used.');
        self::assertSame('ada', $profile->name);
    }

    /**
     * @return iterable<string, array{mixed, bool|null}>
     */
    public static function verificationValues(): iterable
    {
        yield 'false string' => ['false', false];
        yield 'zero' => [0, false];
        yield 'one' => [1, true];
        yield 'garbage' => ['maybe', null];
        yield 'boolean false' => [false, false];
    }

    #[DataProvider('verificationValues')]
    public function testEmailVerifiedValues(mixed $value, ?bool $expected): void
    {
        $idp         = new FakeIdp();
        $idp->nonce  = 'n';
        $idp->claims = ['email_verified' => $value, 'name' => null, 'nickname' => 'ada_l'];

        [, $profile] = (new OidcStack($idp))->client->authenticate($this->generic(), 'c', 'https://app.example.com/cb', null, 'n');
        self::assertSame($expected, $profile->emailVerified);
        self::assertSame('ada_l', $profile->name);
    }

    public function testBasicAuthenticationWhenTheProviderListsOnlyIt(): void
    {
        $idp                = new FakeIdp();
        $idp->nonce         = 'n';
        $idp->discovery     = ['token_endpoint_auth_methods_supported' => null];
        $idp->tokenResponse = ['refresh_token' => 'rt-placeholder', 'expires_in' => 'soon'];
        $credential         = $this->generic()->setClientSecret('s:e c/ret');

        [$tokens] = (new OidcStack($idp))->client->authenticate($credential, 'c', 'https://app.example.com/cb', null, 'n');
        self::assertSame('rt-placeholder', $tokens['refresh_token']);
        self::assertNull($tokens['expires_in']);

        $options = $idp->requests[1]['options'];
        self::assertArrayNotHasKey('client_secret', $this->body($options));
        self::assertContains('Authorization: Basic ' . base64_encode('client-placeholder:' . urlencode('s:e c/ret')), $options['headers'] ?? []);
    }

    public function testSignInWithAppleUsesAGeneratedClientSecretAndTheFirstSignInName(): void
    {
        $idp           = new FakeIdp(OidcProviderCatalog::APPLE_ISSUER, 'com.example.web');
        $idp->nonce    = 'n';
        $idp->userinfo = null;
        $idp->claims   = ['name' => null, 'email_verified' => 'true', 'is_private_email' => 'true'];
        $stack         = new OidcStack($idp);
        $user          = json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace'], 'email' => 'spoofed@example.com'], JSON_THROW_ON_ERROR);

        [, $profile] = $stack->client->authenticate($this->apple(), 'c', 'https://app.example.com/cb', null, 'n', $user);
        self::assertSame('Ada Lovelace', $profile->name);
        self::assertSame('ada@example.com', $profile->email, 'The e-mail of the unsigned user field is never used.');
        self::assertTrue($profile->emailVerified);
        self::assertCount(3, $idp->requests, 'Apple has no userinfo endpoint.');

        $secret = $this->body($idp->requests[1]['options'])['client_secret'] ?? '';
        self::assertIsString($secret);
        $claims = json_decode((string) Base64Url::decode(explode('.', $secret)[1]), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertSame('TEAM123456', $claims['iss']);
        self::assertSame('com.example.web', $claims['sub']);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function appleUserFields(): iterable
    {
        yield 'none (later sign-ins)' => [null];
        yield 'not json' => ['{'];
        yield 'no name' => ['{"email":"x@example.com"}'];
        yield 'too long' => ['{"name":{"firstName":"' . str_repeat('a', 5000) . '"}}'];
    }

    #[DataProvider('appleUserFields')]
    public function testAppleWithoutAUsableNameKeepsNone(?string $user): void
    {
        $idp           = new FakeIdp(OidcProviderCatalog::APPLE_ISSUER, 'com.example.web');
        $idp->nonce    = 'n';
        $idp->userinfo = null;
        $idp->claims   = ['name' => null];

        [, $profile] = (new OidcStack($idp))->client->authenticate($this->apple(), 'c', 'https://app.example.com/cb', null, 'n', $user);
        self::assertNull($profile->name);
    }

    public function testTheAppleUserFieldIsIgnoredForOtherProviders(): void
    {
        $idp         = new FakeIdp();
        $idp->nonce  = 'n';
        $idp->claims = ['name' => null];

        [, $profile] = (new OidcStack($idp))->client->authenticate($this->generic(), 'c', 'https://app.example.com/cb', null, 'n', '{"name":{"firstName":"Eve"}}');
        self::assertNull($profile->name);
    }

    public function testLinkedinNeedsNoNonce(): void
    {
        $idp = new FakeIdp(OidcProviderCatalog::LINKEDIN_ISSUER);

        [, $profile] = (new OidcStack($idp))->client->authenticate($this->credential('linkedin'), 'c', 'https://app.example.com/cb', null, null);
        self::assertSame('subject-1', $profile->id);
    }

    /**
     * @return iterable<string, array{callable(FakeIdp): void, string}>
     */
    public static function failures(): iterable
    {
        yield 'token endpoint error' => [static function (FakeIdp $idp): void { $idp->tokenStatus = 400; }, OidcException::REASON_TOKEN_RESPONSE];
        yield 'no access token' => [static function (FakeIdp $idp): void { $idp->tokenResponse = ['access_token' => null]; }, OidcException::REASON_TOKEN_RESPONSE];
        yield 'empty access token' => [static function (FakeIdp $idp): void { $idp->tokenResponse = ['access_token' => '']; }, OidcException::REASON_TOKEN_RESPONSE];
        yield 'slack style ok false' => [static function (FakeIdp $idp): void { $idp->tokenResponse = ['ok' => false, 'access_token' => null, 'id_token' => null]; }, OidcException::REASON_TOKEN_RESPONSE];
        yield 'no id token' => [static function (FakeIdp $idp): void { $idp->tokenResponse = ['id_token' => null]; }, OidcException::REASON_TOKEN_RESPONSE];
        yield 'forged id token' => [static function (FakeIdp $idp): void { $idp->rawIdToken = TestKeys::sign(['alg' => 'RS256', 'kid' => 'key-1'], ['iss' => 'https://idp.example.com', 'sub' => 'admin', 'aud' => 'client-placeholder', 'exp' => 1795601100, 'iat' => 1795600800, 'nonce' => 'n'], TestKeys::rsa('attacker')); }, OidcException::REASON_SIGNATURE];
        yield 'replayed id token (nonce)' => [static function (FakeIdp $idp): void { $idp->nonce = 'other'; }, OidcException::REASON_NONCE];
        yield 'userinfo error' => [static function (FakeIdp $idp): void { $idp->userinfoStatus = 401; }, OidcException::REASON_USERINFO];
        yield 'userinfo about another subject' => [static function (FakeIdp $idp): void { $idp->userinfo = ['sub' => 'someone-else']; }, OidcException::REASON_USERINFO];
        yield 'discovery for another issuer' => [static function (FakeIdp $idp): void { $idp->discovery = ['issuer' => 'https://evil.example.com']; }, OidcException::REASON_DISCOVERY];
    }

    /**
     * @param callable(FakeIdp): void $bend
     */
    #[DataProvider('failures')]
    public function testFailurePaths(callable $bend, string $reason): void
    {
        $idp        = new FakeIdp();
        $idp->nonce = 'n';
        $bend($idp);

        try {
            (new OidcStack($idp))->client->authenticate($this->generic(), 'c', 'https://app.example.com/cb', null, 'n');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            self::assertStringNotContainsString('secret-placeholder', $e->getMessage());
            self::assertStringNotContainsString('at-placeholder', $e->getMessage());
        }
    }

    public function testAMissingStoredNonceIsRefusedBeforeAnyRequest(): void
    {
        $idp = new FakeIdp();

        try {
            (new OidcStack($idp))->client->authenticate($this->generic(), 'c', 'https://app.example.com/cb', null, null);
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_NONCE, $e->reason);
        }
        self::assertSame([], $idp->requests);
    }

    private function states(): SocialLoginStateStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new SocialLoginStateStore($stack);
    }

    private function credential(string $provider): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider($provider)->setClientId('client-placeholder')->setClientSecret('secret-placeholder');
    }

    private function generic(): SocialLoginCredential
    {
        return $this->credential('okta')->setIssuer('https://idp.example.com');
    }

    private function apple(): SocialLoginCredential
    {
        return $this->credential('apple')
            ->setClientId('com.example.web')
            ->setClientSecret(TestKeys::pem(TestKeys::ec()))
            ->setProviderSettings(['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567']);
    }

    /**
     * @return array<string, mixed>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, mixed> $typed */
        $typed = $query;

        return $typed;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function body(array $options): array
    {
        $body = $options['body'] ?? '';
        if (is_array($body)) {
            /** @var array<string, mixed> $typed */
            $typed = $body;

            return $typed;
        }
        parse_str((string) $body, $parsed);

        /** @var array<string, mixed> $typed */
        $typed = $parsed;

        return $typed;
    }
}
