<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcCredentialRules;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderCatalog;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

final class OidcProviderCatalogTest extends TestCase
{
    public function testTheOAuth2ProvidersAndCustomOnesWithoutIssuerStayOnTheOAuth2Path(): void
    {
        $catalog = new OidcProviderCatalog();
        self::assertFalse($catalog->supports($this->credential('google')));
        self::assertFalse($catalog->supports($this->credential('my_idp')));
        // A preset key configured before 2.3.0 with its own endpoints keeps working as before.
        self::assertFalse($catalog->supports($this->credential('gitlab')->setAuthorizeUrl('https://gitlab.example.com/oauth/authorize')));
        self::assertFalse($catalog->supports($this->credential('linkedin')->setTokenUrl('https://www.linkedin.com/oauth/v2/accessToken')));
        self::assertFalse($catalog->supports($this->credential('slack')->setUserinfoUrl('https://slack.com/api/users.identity')));

        $this->expectException(OidcException::class);
        $catalog->resolve($this->credential('google'));
    }

    public function testGenericProviderFromItsIssuer(): void
    {
        $settings = (new OidcProviderCatalog())->resolve($this->credential('okta')->setIssuer('https://acme.okta.com')->setScopes(['email']));

        self::assertSame(OidcProviderSettings::KIND_GENERIC, $settings->kind);
        self::assertSame('https://acme.okta.com', $settings->issuer);
        self::assertSame(['email', 'openid'], $settings->scopes, 'openid is always requested.');
        self::assertTrue($settings->useNonce);
        self::assertNull($settings->tokenAuthMethod);
        self::assertNull($settings->pkceByDefault);
        self::assertFalse($settings->formPost);
        self::assertSame('okta', $settings->provider());
    }

    public function testAGoogleCredentialWithAnIssuerOptsIntoTheGenericProvider(): void
    {
        self::assertSame(OidcProviderSettings::KIND_GENERIC, (new OidcProviderCatalog())->resolve($this->credential('google')->setIssuer('https://accounts.google.com'))->kind);
    }

    public function testGitlabDefaultsToGitlabComAndTakesASelfHostedBaseUrl(): void
    {
        $catalog = new OidcProviderCatalog();
        $saas    = $catalog->resolve($this->credential('gitlab'));
        self::assertSame('https://gitlab.com', $saas->issuer);
        self::assertSame(['openid', 'profile', 'email'], $saas->scopes);
        self::assertTrue($saas->useNonce);

        $selfHosted = $catalog->resolve($this->credential('GitLab')->setIssuer('https://git.example.org/gitlab'));
        self::assertSame(OidcProviderSettings::KIND_GITLAB, $selfHosted->kind);
        self::assertSame('https://git.example.org/gitlab', $selfHosted->issuer);
    }

    public function testApplePreset(): void
    {
        $settings = (new OidcProviderCatalog())->resolve($this->apple());

        self::assertSame(OidcProviderCatalog::APPLE_ISSUER, $settings->issuer);
        self::assertSame(['name', 'email'], $settings->scopes);
        self::assertTrue($settings->formPost);
        self::assertTrue($settings->useNonce);
        self::assertFalse($settings->pkceByDefault);
        self::assertSame(OidcProviderSettings::AUTH_CLIENT_SECRET_POST, $settings->tokenAuthMethod);
        self::assertSame('TEAM123456', $settings->appleTeamId);
        self::assertSame('KEY1234567', $settings->appleKeyId);
        self::assertSame(['email'], (new OidcProviderCatalog())->resolve($this->apple()->setScopes(['email']))->scopes);
    }

    public function testLinkedinSendsNoNonceAndPostsTheSecret(): void
    {
        $settings = (new OidcProviderCatalog())->resolve($this->credential('linkedin'));
        self::assertSame(OidcProviderCatalog::LINKEDIN_ISSUER, $settings->issuer);
        self::assertFalse($settings->useNonce);
        self::assertFalse($settings->pkceByDefault);
        self::assertSame(OidcProviderSettings::AUTH_CLIENT_SECRET_POST, $settings->tokenAuthMethod);
    }

    public function testSlackPreset(): void
    {
        $settings = (new OidcProviderCatalog())->resolve($this->credential('slack')->setIssuer('https://slack.com/'));
        self::assertSame(OidcProviderCatalog::SLACK_ISSUER, $settings->issuer, 'Repeating the fixed issuer is allowed.');
        self::assertTrue($settings->useNonce);
    }

    /**
     * @return iterable<string, array{SocialLoginCredential}>
     */
    public static function invalid(): iterable
    {
        yield 'apple pointed elsewhere' => [(new SocialLoginCredential())->setProvider('apple')->setClientId('c')->setClientSecret('s')->setIssuer('https://evil.example.com')];
        yield 'linkedin pointed elsewhere' => [(new SocialLoginCredential())->setProvider('linkedin')->setClientId('c')->setClientSecret('s')->setIssuer('https://evil.example.com')];
        yield 'slack pointed elsewhere' => [(new SocialLoginCredential())->setProvider('slack')->setClientId('c')->setClientSecret('s')->setIssuer('https://evil.example.com')];
        yield 'apple without team and key' => [(new SocialLoginCredential())->setProvider('apple')->setClientId('c')->setClientSecret('s')];
        yield 'apple without key id' => [(new SocialLoginCredential())->setProvider('apple')->setClientId('c')->setClientSecret('s')->setProviderSettings(['team_id' => 'TEAM123456'])];
        yield 'no client id' => [(new SocialLoginCredential())->setProvider('gitlab')->setClientSecret('s')];
        yield 'no client secret' => [(new SocialLoginCredential())->setProvider('gitlab')->setClientId('c')];
    }

    #[DataProvider('invalid')]
    public function testInvalidSettingsAreAConfigurationFailure(SocialLoginCredential $credential): void
    {
        try {
            (new OidcProviderCatalog())->resolve($credential);
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_CONFIGURATION, $e->reason);
        }
    }

    public function testARowWrittenAroundTheSettersIsCheckedAgainWhenRead(): void
    {
        $credential = $this->credential('okta');
        (new ReflectionProperty(SocialLoginCredential::class, 'issuer'))->setValue($credential, 'http://10.0.0.1');
        $this->expectException(OidcException::class);
        (new OidcProviderCatalog())->resolve($credential);
    }

    public function testBadProviderSettingsInARawRowAreRefused(): void
    {
        $credential = $this->apple();
        (new ReflectionProperty(SocialLoginCredential::class, 'providerSettings'))->setValue($credential, ['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'other' => 'x']);
        $this->expectException(OidcException::class);
        (new OidcProviderCatalog())->resolve($credential);
    }

    public function testCredentialIssuerAndSettingsValidation(): void
    {
        $credential = new SocialLoginCredential();
        self::assertNull($credential->setIssuer('  ')->getIssuer());
        self::assertNull($credential->setIssuer(null)->getIssuer());
        self::assertSame('https://idp.example.com/realms/a', $credential->setIssuer(' https://idp.example.com/realms/a ')->getIssuer());
        self::assertSame([], $credential->getProviderSettings());
        self::assertSame(['team_id' => 'TEAM123456'], $credential->setProviderSettings(['team_id' => 'TEAM123456', 'key_id' => null])->getProviderSettings());
        self::assertSame([], $credential->setProviderSettings(['team_id' => null])->getProviderSettings());

        foreach (['http://idp.example.com', 'https://idp.example.com/?a=1', 'https://idp.example.com/#x', 'https://u:p@idp.example.com', 'https://127.0.0.1', 'https://' . str_repeat('a', 520) . '.com', 'not a url'] as $bad) {
            self::assertNotNull(OidcCredentialRules::issuerError($bad), $bad);
            try {
                $credential->setIssuer($bad);
                self::fail('Expected a refusal of ' . $bad);
            } catch (InvalidArgumentException) {
            }
        }
        foreach ([['secret' => 'x'], ['team_id' => 'short'], ['key_id' => 'lowercase1'], ['team_id' => 123]] as $bad) {
            self::assertNotNull(OidcCredentialRules::settingsError($bad));
            try {
                $credential->setProviderSettings($bad); // @phpstan-ignore argument.type (an invalid row on purpose)
                self::fail('Expected a refusal.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertNull(OidcCredentialRules::setting([], 'team_id'));
    }

    private function credential(string $provider): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider($provider)->setClientId('client-placeholder')->setClientSecret('secret-placeholder');
    }

    private function apple(): SocialLoginCredential
    {
        return $this->credential('apple')->setProviderSettings(['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567']);
    }
}
