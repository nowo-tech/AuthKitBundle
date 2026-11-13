<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;

use function parse_str;
use function parse_url;
use function substr_count;

use const PHP_URL_QUERY;

/**
 * 1.48 `authorization_params`: extra parameters of the authorization request (prompt, access_type, login_hint …),
 * merged into the authorize URL by the client so a host never concatenates `?` or `&` itself.
 */
final class AuthorizationParamsTest extends TestCase
{
    private function client(): OAuth2Client
    {
        return new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog());
    }

    private function credential(string $provider = 'custom'): SocialLoginCredential
    {
        return (new SocialLoginCredential())
            ->setProvider($provider)
            ->setClientId('client-123')
            ->setAuthorizeUrl('https://idp.test/authorize')
            ->setTokenUrl('https://idp.test/token')
            ->setUserinfoUrl('https://idp.test/userinfo');
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        $raw = [];
        parse_str($query, $raw);

        $params = [];
        foreach ($raw as $name => $value) {
            self::assertIsString($value, 'The authorize URL carries flat parameters only.');
            $params[(string) $name] = $value;
        }

        return $params;
    }

    public function testCredentialParamsReachTheAuthorizeUrl(): void
    {
        $credential = $this->credential()->setAuthorizationParams([
            'prompt'      => 'select_account',
            'access_type' => 'offline',
            'login_hint'  => 'ada@example.com',
        ]);

        $params = $this->query($this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'state-1'));

        self::assertSame('select_account', $params['prompt']);
        self::assertSame('offline', $params['access_type']);
        self::assertSame('ada@example.com', $params['login_hint']);
    }

    /**
     * The client assembles the URL: a host sets names and values, never the separator or the encoding.
     */
    public function testValuesAreUrlEncodedAndAppendedWithTheRightSeparator(): void
    {
        $credential = $this->credential()->setAuthorizationParams(['login_hint' => 'ada+dev@example.com', 'ui locales' => 'es-ES de']);
        $withQuery  = $this->credential()->setAuthorizeUrl('https://idp.test/authorize?tenant=acme')->setAuthorizationParams(['prompt' => 'select_account']);
        $client     = $this->client();

        $url = $client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'state-1');
        self::assertStringContainsString('login_hint=ada%2Bdev%40example.com', $url);
        self::assertStringContainsString('ui+locales=es-ES+de', $url);
        self::assertSame('ada+dev@example.com', $this->query($url)['login_hint']);

        $second = $client->buildAuthorizeUrl($withQuery, 'https://app.test/cb', 'state-1');
        self::assertStringContainsString('https://idp.test/authorize?tenant=acme&', $second);
        self::assertSame('acme', $this->query($second)['tenant']);
        self::assertSame('select_account', $this->query($second)['prompt']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function reservedParams(): iterable
    {
        foreach (OAuth2Client::RESERVED_AUTHORIZATION_PARAMS as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The protocol parameters the bundle owns are refused at write time, loudly: a host cannot move `state` or
     * `redirect_uri` (CSRF / PKCE binding, open redirect).
     */
    #[DataProvider('reservedParams')]
    public function testReservedParamsAreRefusedOnTheCredential(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('owned by the bundle');

        $this->credential()->setAuthorizationParams([$name => 'attacker']);
    }

    /**
     * A row written outside the entity (raw SQL, a fixture) still cannot override them: the client drops reserved
     * names, whatever their case, and writes its own parameters last.
     */
    public function testReservedParamsOfARawRowAreIgnoredByTheClient(): void
    {
        $credential = $this->credential();
        $reflection = new ReflectionProperty(SocialLoginCredential::class, 'authorizationParams');
        $reflection->setValue($credential, [
            'state'        => 'attacker-state',
            'REDIRECT_URI' => 'https://evil.test/steal',
            'scope'        => 'admin',
            '  '           => 'nameless',
            'prompt'       => 'select_account',
        ]);

        $url    = $this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'state-1', 'verifier-0123456789-0123456789-0123456789');
        $params = $this->query($url);

        self::assertSame('state-1', $params['state']);
        self::assertSame('https://app.test/cb', $params['redirect_uri']);
        self::assertSame('openid email profile', $params['scope']);
        self::assertSame('code', $params['response_type']);
        self::assertSame('select_account', $params['prompt']);
        self::assertSame(Pkce::challenge('verifier-0123456789-0123456789-0123456789'), $params['code_challenge']);
        self::assertStringNotContainsString('evil.test', $url);
        self::assertStringNotContainsString('attacker-state', $url);
    }

    /**
     * Profile configuration (`social_login.authorization_params`) is merged under the credential's own map, keyed
     * case-insensitively by provider.
     */
    public function testProfileConfigurationAppliesAndTheCredentialWins(): void
    {
        $client     = $this->client();
        $credential = $this->credential('my_idp');
        $overrides  = ['MY_IDP' => ['prompt' => 'consent', 'access_type' => 'offline'], 'other' => ['prompt' => 'none']];

        $params = $this->query($client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, $overrides));
        self::assertSame('consent', $params['prompt']);
        self::assertSame('offline', $params['access_type']);

        $credential->setAuthorizationParams(['prompt' => 'select_account']);
        $params = $this->query($client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, $overrides));
        self::assertSame('select_account', $params['prompt']);
        self::assertSame('offline', $params['access_type']);
    }

    /**
     * A null value removes the parameter instead of sending it empty — the documented opt-out. A later layer can put
     * it back.
     */
    public function testNullRemovesAParameter(): void
    {
        $client     = $this->client();
        $credential = $this->credential('my_idp')->setAuthorizationParams(['prompt' => null]);
        $overrides  = ['my_idp' => ['prompt' => 'consent']];

        $url = $client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, $overrides);
        self::assertArrayNotHasKey('prompt', $this->query($url));
        self::assertStringNotContainsString('prompt', $url);

        $credential->setAuthorizationParams([]);
        self::assertSame('consent', $this->query($client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, $overrides))['prompt']);
    }

    /**
     * Precedence does not depend on how a layer spells the name: a credential removes a parameter of the
     * configuration (or of a built-in default) whatever its case and spacing.
     */
    public function testNamesAreComparedCaseAndSpaceInsensitivelyAcrossLayers(): void
    {
        $client     = $this->client();
        $credential = $this->credential('my_idp')->setAuthorizationParams([' PROMPT ' => null, 'Access_Type' => 'offline']);
        $overrides  = ['my_idp' => ['prompt' => 'consent', 'access_type' => 'online']];

        self::assertSame(['access_type' => 'offline'], $client->authorizationParams($credential, $overrides));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: ?string}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['select_account', 'select_account'];
        yield 'int' => [7, '7'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'null removes' => [null, null];
    }

    #[DataProvider('values')]
    public function testScalarValuesBecomeStrings(mixed $value, ?string $expected): void
    {
        $credential = $this->credential()->setAuthorizationParams(['prompt' => $value]);
        $params     = $this->query($this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st'));

        if ($expected === null) {
            self::assertArrayNotHasKey('prompt', $params);
            self::assertSame(['prompt' => null], $credential->getAuthorizationParams());

            return;
        }

        self::assertSame($expected, $params['prompt']);
        self::assertSame(['prompt' => $expected], $credential->getAuthorizationParams());
    }

    public function testParameterNameCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty name');

        $this->credential()->setAuthorizationParams(['  ' => 'x']);
    }

    public function testNonScalarValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scalar value or null');

        $this->credential()->setAuthorizationParams(['prompt' => ['select_account']]);
    }

    /**
     * Names are trimmed and lower-cased on the wire, and a non-map configuration entry is ignored.
     */
    public function testNamesAreNormalisedAndNonMapConfigurationIsIgnored(): void
    {
        $credential = $this->credential('my_idp')->setAuthorizationParams([' Prompt ' => 'select_account']);
        $params     = $this->query($this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, ['my_idp' => 'nonsense']));

        self::assertSame('select_account', $params['prompt']);
        self::assertSame(['Prompt' => 'select_account'], $credential->getAuthorizationParams());
    }

    /**
     * Nothing is memoised per call (REQ-RUNTIME-002): the same client answers the arguments of each request.
     */
    public function testClientKeepsNoStateBetweenCalls(): void
    {
        $client = $this->client();
        $first  = $this->credential('my_idp')->setAuthorizationParams(['prompt' => 'select_account']);
        $second = $this->credential('my_idp');

        self::assertSame(['prompt' => 'select_account'], $client->authorizationParams($first));
        self::assertSame([], $client->authorizationParams($second));
        self::assertSame(['prompt' => 'select_account'], $client->authorizationParams($first));
    }

    /**
     * 1.48: the built-in google / microsoft providers ask for the account chooser by default, so a multi-account
     * browser is never bound to whichever account it happened to have open.
     */
    public function testGoogleAndMicrosoftAskForTheAccountChooserByDefault(): void
    {
        foreach (['google', 'microsoft'] as $provider) {
            $credential = (new SocialLoginCredential())->setProvider($provider)->setClientId('client-123');
            $params     = $this->query($this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st'));

            self::assertSame('select_account', $params['prompt'], $provider . ' asks which account to use.');
        }

        // A custom provider gets nothing: an IdP that rejects unknown parameters keeps working.
        self::assertSame([], $this->client()->authorizationParams($this->credential('my_idp')));
    }

    /**
     * The documented opt-out: a null value removes the default instead of sending it empty — from the profile
     * configuration or from the credential.
     */
    public function testTheAccountChooserCanBeTurnedOff(): void
    {
        $client     = $this->client();
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('client-123');

        $byConfig = $client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st', null, ['google' => ['prompt' => null]]);
        self::assertArrayNotHasKey('prompt', $this->query($byConfig));
        self::assertStringNotContainsString('prompt', $byConfig);

        $credential->setAuthorizationParams(['prompt' => null]);
        $byCredential = $client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st');
        self::assertArrayNotHasKey('prompt', $this->query($byCredential));
        self::assertStringNotContainsString('prompt', $byCredential);
    }

    /**
     * Another value replaces the default rather than being added next to it.
     */
    public function testTheDefaultCanBeReplaced(): void
    {
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('client-123')->setAuthorizationParams(['prompt' => 'consent']);

        self::assertSame(['prompt' => 'consent'], $this->client()->authorizationParams($credential));
    }

    /**
     * Audit M-1: a row whose JSON column was added by the single-statement migration Doctrine generates hydrates as
     * PHP null (a JSON scalar `null` on MySQL, the empty string on MariaDB). Reading it must answer an empty map
     * instead of raising a TypeError while hydrating, which would take down every page that consults the social
     * gate — the whole login page, not just the buttons.
     */
    public function testANullJsonColumnReadsAsAnEmptyMap(): void
    {
        $credential = $this->credential('google');
        foreach (['authorizationParams', 'scopes'] as $property) {
            (new ReflectionProperty(SocialLoginCredential::class, $property))->setValue($credential, null);
        }

        self::assertSame([], $credential->getAuthorizationParams());
        self::assertSame([], $credential->getScopes());

        // The page still renders and the provider still gets its defaults and the catalogue's scopes.
        $params = $this->query($this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st'));
        self::assertSame('select_account', $params['prompt']);
        self::assertSame('openid email profile', $params['scope']);
    }

    /**
     * Audit L-2: before `authorization_params` existed the only way to send an extra parameter was to embed it in
     * `authorize_url`. Repeating it would break the request (RFC 6749 §3.1; Google and Entra answer
     * invalid_request), so the parameter we send wins and the rest of the host's query survives untouched.
     */
    public function testAParameterAlreadyInTheAuthorizeUrlIsNotRepeated(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setClientId('client-123')
            ->setAuthorizeUrl('https://accounts.google.test/auth?tenant=acme&PROMPT=consent&hd=example.com');

        $url = $this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st');

        self::assertSame(1, substr_count($url, 'prompt='), 'The parameter is sent once.');
        self::assertSame('select_account', $this->query($url)['prompt'], 'Ours wins over the one in authorize_url.');
        self::assertStringContainsString('https://accounts.google.test/auth?tenant=acme&hd=example.com&', $url);
        self::assertSame('acme', $this->query($url)['tenant']);
        self::assertSame('example.com', $this->query($url)['hd']);
    }

    /**
     * The same for a parameter the host configured explicitly, and for the bundle's own protocol parameters: a
     * `state` or `redirect_uri` pasted into authorize_url is replaced, never repeated.
     */
    public function testTheAuthorizeUrlNeverRepeatsAConfiguredOrProtocolParameter(): void
    {
        $credential = $this->credential('my_idp')
            ->setAuthorizeUrl('https://idp.test/authorize?prompt=consent&state=stale&redirect_uri=https%3A%2F%2Fevil.test&keep=1')
            ->setAuthorizationParams(['prompt' => 'login']);

        $url    = $this->client()->buildAuthorizeUrl($credential, 'https://app.test/cb', 'state-1');
        $params = $this->query($url);

        self::assertSame('login', $params['prompt']);
        self::assertSame('state-1', $params['state']);
        self::assertSame('https://app.test/cb', $params['redirect_uri']);
        self::assertSame('1', $params['keep']);
        self::assertSame(1, substr_count($url, 'state='));
        self::assertSame(1, substr_count($url, 'redirect_uri='));
        self::assertStringNotContainsString('evil.test', $url);
        self::assertStringNotContainsString('stale', $url);
    }

    /**
     * An authorize URL with an empty or value-less query keeps working.
     */
    public function testAuthorizeUrlWithAnOddQueryStillBuilds(): void
    {
        $client = $this->client();

        $empty = $client->buildAuthorizeUrl($this->credential('my_idp')->setAuthorizeUrl('https://idp.test/authorize?'), 'https://app.test/cb', 'st');
        self::assertStringStartsWith('https://idp.test/authorize?client_id=', $empty);

        $flag = $client->buildAuthorizeUrl($this->credential('my_idp')->setAuthorizeUrl('https://idp.test/authorize?debug'), 'https://app.test/cb', 'st');
        self::assertStringStartsWith('https://idp.test/authorize?debug&', $flag);
        self::assertSame('st', $this->query($flag)['state']);
    }

    /**
     * Audit L-3: edge white space on the provider key no longer splits the per-provider settings from the rest.
     * Before the trim such a credential matched the route and the brand mark, but got no defaults, ignored its own
     * authorization_params and silently sent no PKCE.
     */
    public function testProviderKeyWithEdgeWhiteSpaceStillResolvesItsSettings(): void
    {
        $client     = $this->client();
        $credential = (new SocialLoginCredential())->setProvider('  google  ')->setClientId('client-123');

        self::assertSame('google', $credential->getProvider(), 'The key is trimmed at the source.');
        self::assertSame(['prompt' => 'select_account'], $client->authorizationParams($credential));
        self::assertTrue($client->usesPkce($credential), 'PKCE is not silently skipped.');
        self::assertSame(['prompt' => 'consent'], $client->authorizationParams($credential, [' GOOGLE ' => ['prompt' => 'consent']]));
        self::assertFalse($client->usesPkce($credential, [' Google ' => false]));
    }

    public function testCredentialStartsWithoutParams(): void
    {
        self::assertSame([], (new SocialLoginCredential())->getAuthorizationParams());
    }
}
