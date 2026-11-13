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

    public function testCredentialStartsWithoutParams(): void
    {
        self::assertSame([], (new SocialLoginCredential())->getAuthorizationParams());
    }
}
