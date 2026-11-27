<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialPolicy;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

/**
 * 2.7.0: `login_hint`, the test marker and the validated ID token claims of the OpenID Connect client.
 */
final class OidcEnterpriseClientTest extends TestCase
{
    public function testBeginForwardsTheHintAndMarksATest(): void
    {
        $stack  = new OidcStack(new FakeIdp());
        $states = $this->states();
        // The credential's own login_hint never wins over the validated one of this start.
        $credential = $this->generic()->setAuthorizationParams(['login_hint' => 'other@example.com']);

        $begin = $stack->client->begin($credential, 'okta', 'https://app.example.com/cb', $states, [], [], 'ada@example.com', true);
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $query);
        self::assertSame('ada@example.com', $query['login_hint']);
        self::assertTrue($states->isTestPending('okta', $begin['state']));

        $begin = $stack->client->begin($this->generic(), 'okta', 'https://app.example.com/cb', $states, [], [], "ada@example.com\r\n");
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('login_hint', $query, 'An invalid hint is dropped.');
        self::assertFalse($states->isTestPending('okta', $begin['state']));
    }

    /**
     * SECURITY: the userinfo answer fills `raw`, never `idTokenClaims`; an `amr` there does not exempt the second step.
     */
    public function testTheIdTokenClaimsNeverHoldUserinfo(): void
    {
        $idp           = new FakeIdp();
        $idp->nonce    = 'n-1';
        $idp->userinfo = ['amr' => ['mfa'], 'acr' => 'urn:high', 'hd' => 'evil.example'];
        $stack         = new OidcStack($idp);

        [, $profile] = $stack->client->authenticate($this->generic(), 'code-1', 'https://app.example.com/cb', null, 'n-1');

        self::assertSame(['mfa'], $profile->raw['amr'] ?? null, 'Userinfo still completes the raw profile.');
        self::assertIsArray($profile->idTokenClaims);
        self::assertArrayNotHasKey('amr', $profile->idTokenClaims);
        self::assertArrayNotHasKey('acr', $profile->idTokenClaims);
        self::assertArrayNotHasKey('hd', $profile->idTokenClaims);
        self::assertArrayNotHasKey('nonce', $profile->idTokenClaims);
        self::assertSame('subject-1', $profile->idTokenClaims['sub']);
        self::assertSame('https://idp.example.com', $profile->idTokenClaims['iss']);

        $policy = $this->generic()->setMfaFirstFactorPolicy('exempt_when_amr', ['mfa']);
        self::assertSame('required', SocialCredentialPolicy::mfaRule($policy, $profile));

        // The same value signed into the ID token exempts.
        $idp->claims = ['amr' => ['mfa']];
        [, $signed]  = $stack->client->authenticate($this->generic(), 'code-1', 'https://app.example.com/cb', null, 'n-1');
        self::assertSame('exempt', SocialCredentialPolicy::mfaRule($policy, $signed));
    }

    public function testOAuth2AuthorizeUrlForwardsTheHint(): void
    {
        $client = new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog());
        $google = (new SocialLoginCredential())->setProvider('google')->setClientId('id')->setClientSecret('secret');

        parse_str((string) parse_url($client->buildAuthorizeUrl($google, 'https://app.example.com/cb', 's', null, [], 'ada@example.com'), PHP_URL_QUERY), $query);
        self::assertSame('ada@example.com', $query['login_hint']);
        parse_str((string) parse_url($client->buildAuthorizeUrl($google, 'https://app.example.com/cb', 's'), PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('login_hint', $query);
    }

    private function states(): SocialLoginStateStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new SocialLoginStateStore($stack);
    }

    private function generic(): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider('okta')->setClientId('client-placeholder')->setClientSecret('secret-placeholder')->setIssuer('https://idp.example.com');
    }
}
