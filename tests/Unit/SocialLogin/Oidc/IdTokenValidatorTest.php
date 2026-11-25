<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderMetadata;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function hash_hmac;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

final class IdTokenValidatorTest extends TestCase
{
    private const NOW = 1795600800; // 2026-11-25 10:00:00 UTC (OidcStack's clock)

    public function testAValidRs256TokenGivesItsClaims(): void
    {
        [$stack, $idp] = $this->stack();
        $idp->nonce    = 'n-1';

        $claims = $stack->validator->validate($idp->idToken(), $this->metadata(), 'client-placeholder', 'n-1');
        self::assertSame('subject-1', $claims['sub']);
        self::assertSame('ada@example.com', $claims['email']);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function ecAlgorithms(): iterable
    {
        yield 'ES256' => ['ES256', 'prime256v1', 32];
        yield 'ES384' => ['ES384', 'secp384r1', 48];
        yield 'ES512' => ['ES512', 'secp521r1', 66];
    }

    #[DataProvider('ecAlgorithms')]
    public function testEcdsaTokens(string $alg, string $curve, int $length): void
    {
        [$stack, $idp] = $this->stack();
        $key           = TestKeys::ec($curve);
        $idp->jwks     = ['keys' => [TestKeys::jwk($key, 'ec-1')]];
        $token         = TestKeys::sign(['alg' => $alg, 'kid' => 'ec-1'], $this->claims(), $key);

        self::assertSame('subject-1', $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null)['sub']);
    }

    public function testATokenWithoutKeyIdTriesEveryFittingKeyAndFloatTimesAreAccepted(): void
    {
        [$stack, $idp] = $this->stack();
        $idp->jwks     = ['keys' => [TestKeys::jwk(TestKeys::rsa('other'), 'x'), TestKeys::jwk(TestKeys::rsa(), 'y')]];
        $claims        = ['exp' => self::NOW + 100.5, 'iat' => self::NOW - 0.5, 'nbf' => self::NOW] + $this->claims();
        $token         = TestKeys::sign(['alg' => 'RS256'], $claims, TestKeys::rsa());

        self::assertSame('subject-1', $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null)['sub']);
    }

    public function testSeveralAudiencesWithTheRightAuthorizedParty(): void
    {
        [$stack] = $this->stack();
        $token   = TestKeys::sign(['alg' => 'RS256', 'kid' => 'key-1'], ['aud' => ['client-placeholder', 'other'], 'azp' => 'client-placeholder'] + $this->claims(), TestKeys::rsa());

        self::assertSame('subject-1', $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null)['sub']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        $header  = Base64Url::encode('{"alg":"RS256","kid":"key-1"}');
        $payload = Base64Url::encode('{"sub":"x"}');

        yield 'too large' => [str_repeat('a', 65537), OidcException::REASON_ID_TOKEN];
        yield 'two parts' => [$header . '.' . $payload, OidcException::REASON_ID_TOKEN];
        yield 'unsigned' => [$header . '.' . $payload . '.', OidcException::REASON_ID_TOKEN];
        yield 'header not base64url' => ['a+b.' . $payload . '.c2ln', OidcException::REASON_ID_TOKEN];
        yield 'payload not json' => [$header . '.' . Base64Url::encode('not json') . '.c2ln', OidcException::REASON_ID_TOKEN];
        yield 'payload a list' => [$header . '.' . Base64Url::encode('[1]') . '.c2ln', OidcException::REASON_ID_TOKEN];
        yield 'payload empty object' => [$header . '.' . Base64Url::encode('{}') . '.c2ln', OidcException::REASON_ID_TOKEN];
        yield 'signature not base64url' => [$header . '.' . $payload . '.a+b', OidcException::REASON_ID_TOKEN];
    }

    #[DataProvider('malformed')]
    public function testMalformedTokens(string $token, string $reason): void
    {
        [$stack] = $this->stack();
        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), $reason);
    }

    public function testAlgNoneIsRefused(): void
    {
        [$stack] = $this->stack();
        $token   = Base64Url::encode('{"alg":"none"}') . '.' . Base64Url::encode(json_encode($this->claims(), JSON_THROW_ON_ERROR)) . '.c2ln';
        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    public function testHs256SignedWithThePublicKeyIsRefused(): void
    {
        [$stack] = $this->stack();
        // The classic key-confusion attack: HMAC "signed" with the provider's public key as the secret.
        $secret = json_encode(TestKeys::jwk(TestKeys::rsa(), 'key-1'), JSON_THROW_ON_ERROR);
        $input  = Base64Url::encode('{"alg":"HS256","kid":"key-1"}') . '.' . Base64Url::encode(json_encode($this->claims(), JSON_THROW_ON_ERROR));
        $token  = $input . '.' . Base64Url::encode(hash_hmac('sha256', $input, $secret, true));

        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    public function testAnAlgorithmOffTheConfiguredAllowListIsRefused(): void
    {
        [$stack] = $this->stack(new OidcOptions(['ES256']));
        $this->assertRefused(fn () => $stack->validator->validate($this->token(), $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badHeaders(): iterable
    {
        yield 'critical header' => [['crit' => ['exp']], OidcException::REASON_ID_TOKEN];
        yield 'not a JWT type' => [['typ' => 'at+jwt'], OidcException::REASON_ID_TOKEN];
        yield 'type not a string' => [['typ' => 5], OidcException::REASON_ID_TOKEN];
        yield 'key id not a string' => [['kid' => 5], OidcException::REASON_ID_TOKEN];
        yield 'unknown key id' => [['kid' => 'nope'], OidcException::REASON_SIGNATURE];
        yield 'embedded jwk is ignored' => [['kid' => 'nope', 'jwk' => ['kty' => 'RSA']], OidcException::REASON_SIGNATURE];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('badHeaders')]
    public function testBadHeaders(array $header, string $reason): void
    {
        [$stack] = $this->stack();
        $token   = TestKeys::sign($header + ['alg' => 'RS256', 'kid' => 'key-1'], $this->claims(), TestKeys::rsa());
        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), $reason);
    }

    public function testASignatureByAnotherKeyIsRefused(): void
    {
        [$stack] = $this->stack();
        $token   = TestKeys::sign(['alg' => 'RS256', 'kid' => 'key-1'], $this->claims(), TestKeys::rsa('attacker'));
        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    public function testAnEcdsaSignatureOfTheWrongLengthIsRefused(): void
    {
        [$stack, $idp] = $this->stack();
        $idp->jwks     = ['keys' => [TestKeys::jwk(TestKeys::ec(), 'ec-1')]];
        $token         = TestKeys::sign(['alg' => 'ES256', 'kid' => 'ec-1'], $this->claims(), TestKeys::ec());
        $this->assertRefused(fn () => $stack->validator->validate($token . 'AAAA', $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unfitKeys(): iterable
    {
        yield 'encryption key' => [['use' => 'enc']];
        yield 'key for another algorithm' => [['alg' => 'RS512']];
        yield 'key not for verifying' => [['key_ops' => ['encrypt']]];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('unfitKeys')]
    public function testAKeyThatDoesNotFitIsNotUsed(array $extra): void
    {
        [$stack, $idp] = $this->stack();
        $idp->jwks     = ['keys' => [TestKeys::jwk(TestKeys::rsa(), 'key-1', $extra)]];
        $this->assertRefused(fn () => $stack->validator->validate($this->token(), $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    public function testAnRsAlgorithmNeverUsesAnEcKeyNorTheWrongCurve(): void
    {
        [$stack, $idp] = $this->stack();
        $idp->jwks     = ['keys' => [TestKeys::jwk(TestKeys::ec(), 'key-1'), TestKeys::jwk(TestKeys::ec('secp384r1'), 'ec')]];
        $this->assertRefused(fn () => $stack->validator->validate($this->token(), $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);

        $token = TestKeys::sign(['alg' => 'ES256', 'kid' => 'ec'], $this->claims(), TestKeys::ec());
        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    public function testAKeyRefusedByTheConverterIsSkipped(): void
    {
        [$stack, $idp] = $this->stack();
        $idp->jwks     = ['keys' => [TestKeys::jwk(TestKeys::rsa(), 'key-1', ['d' => 'private'])]];
        $this->assertRefused(fn () => $stack->validator->validate($this->token(), $this->metadata(), 'client-placeholder', null), OidcException::REASON_SIGNATURE);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string|null}>
     */
    public static function badClaims(): iterable
    {
        yield 'another issuer' => [['iss' => 'https://evil.example.com'], OidcException::REASON_CLAIMS, null];
        yield 'issuer with a trailing slash' => [['iss' => 'https://idp.example.com/'], OidcException::REASON_CLAIMS, null];
        yield 'no audience' => [['aud' => null], OidcException::REASON_CLAIMS, null];
        yield 'another audience' => [['aud' => 'other-client'], OidcException::REASON_CLAIMS, null];
        yield 'audience map' => [['aud' => ['a' => 'client-placeholder']], OidcException::REASON_CLAIMS, null];
        yield 'several audiences without azp' => [['aud' => ['client-placeholder', 'other']], OidcException::REASON_CLAIMS, null];
        yield 'azp of another client' => [['azp' => 'other'], OidcException::REASON_CLAIMS, null];
        yield 'expired' => [['exp' => self::NOW - 61], OidcException::REASON_CLAIMS, null];
        yield 'no expiry' => [['exp' => null], OidcException::REASON_CLAIMS, null];
        yield 'expiry not a number' => [['exp' => 'tomorrow'], OidcException::REASON_CLAIMS, null];
        yield 'issued in the future' => [['iat' => self::NOW + 61], OidcException::REASON_CLAIMS, null];
        yield 'issued too long ago' => [['iat' => self::NOW - 661], OidcException::REASON_CLAIMS, null];
        yield 'no issue time' => [['iat' => null], OidcException::REASON_CLAIMS, null];
        yield 'not valid yet' => [['nbf' => self::NOW + 61], OidcException::REASON_CLAIMS, null];
        yield 'nbf not a number' => [['nbf' => 'soon'], OidcException::REASON_CLAIMS, null];
        yield 'nonce mismatch' => [['nonce' => 'other'], OidcException::REASON_NONCE, 'expected'];
        yield 'nonce missing' => [[], OidcException::REASON_NONCE, 'expected'];
        yield 'nonce not a string' => [['nonce' => 7], OidcException::REASON_NONCE, 'expected'];
        yield 'no subject' => [['sub' => null], OidcException::REASON_CLAIMS, null];
        yield 'empty subject' => [['sub' => ''], OidcException::REASON_CLAIMS, null];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badClaims')]
    public function testBadClaims(array $override, string $reason, ?string $nonce): void
    {
        [$stack] = $this->stack();
        $claims  = $this->claims();
        foreach ($override as $name => $value) {
            if ($value === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }
        $token = TestKeys::sign(['alg' => 'RS256', 'kid' => 'key-1'], $claims, TestKeys::rsa());

        $this->assertRefused(fn () => $stack->validator->validate($token, $this->metadata(), 'client-placeholder', $nonce), $reason);
    }

    /**
     * @return array{0: OidcStack, 1: FakeIdp}
     */
    private function stack(OidcOptions $options = new OidcOptions()): array
    {
        $idp = new FakeIdp();

        return [new OidcStack($idp, $options), $idp];
    }

    private function metadata(): OidcProviderMetadata
    {
        return new OidcProviderMetadata('https://idp.example.com', 'https://idp.example.com/authorize', 'https://idp.example.com/token', 'https://idp.example.com/jwks', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        return ['iss' => 'https://idp.example.com', 'sub' => 'subject-1', 'aud' => 'client-placeholder', 'exp' => self::NOW + 300, 'iat' => self::NOW];
    }

    private function token(): string
    {
        return TestKeys::sign(['alg' => 'RS256', 'kid' => 'key-1'], $this->claims(), TestKeys::rsa());
    }

    private function assertRefused(callable $call, string $reason): void
    {
        try {
            $call();
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
