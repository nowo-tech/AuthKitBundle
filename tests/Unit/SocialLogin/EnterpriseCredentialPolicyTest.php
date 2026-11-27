<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\SocialLogin\LoginHint;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialPolicy;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

use function is_string;
use function str_repeat;

/**
 * 2.7.0 per-credential policies (Beacon spec 221): listed flag, `link_existing_account`, MFA first-factor policy and
 * `login_hint`. Fail closed everywhere.
 */
final class EnterpriseCredentialPolicyTest extends TestCase
{
    public function testDefaultsKeepTheProfileBehaviour(): void
    {
        $credential = new SocialLoginCredential();

        self::assertTrue($credential->isListed());
        self::assertNull($credential->getLinkExistingAccount());
        self::assertNull($credential->getMfaFirstFactor());
        self::assertSame([], $credential->getMfaAcceptedAmr());
        self::assertSame('reset_password', SocialCredentialPolicy::linkExistingAccount($credential, $this->profile()));
        self::assertSame('keep', SocialCredentialPolicy::linkExistingAccount(null, $this->profile(['link_existing_account' => 'keep'])));
        self::assertNull(SocialCredentialPolicy::mfaRule($credential, $this->social(['amr' => ['mfa']])));
    }

    public function testListedFlag(): void
    {
        $credential = new SocialLoginCredential();
        $before     = $credential->getUpdatedAt();

        self::assertFalse($credential->setListed(false)->isListed());
        self::assertTrue($credential->setListed(true)->isListed());
        self::assertTrue($credential->setListed(null)->isListed());
        self::assertGreaterThanOrEqual($before, $credential->getUpdatedAt());
    }

    public function testLinkExistingAccountPerCredentialWinsOverTheProfile(): void
    {
        $credential = (new SocialLoginCredential())->setLinkExistingAccount('deny');

        self::assertSame('deny', $credential->getLinkExistingAccount());
        self::assertSame('deny', SocialCredentialPolicy::linkExistingAccount($credential, $this->profile(['link_existing_account' => 'keep'])));
        self::assertSame('keep', SocialCredentialPolicy::linkExistingAccount($credential->setLinkExistingAccount('keep'), $this->profile()));
        self::assertNull($credential->setLinkExistingAccount(null)->getLinkExistingAccount());

        $this->expectException(InvalidArgumentException::class);
        $credential->setLinkExistingAccount('allow');
    }

    public function testUnknownStoredValuesAreReadAsTheStrictestOnes(): void
    {
        $credential = new SocialLoginCredential();
        (new ReflectionProperty(SocialLoginCredential::class, 'linkExistingAccount'))->setValue($credential, 'link_all');
        (new ReflectionProperty(SocialLoginCredential::class, 'mfaFirstFactor'))->setValue($credential, 'skip');
        (new ReflectionProperty(SocialLoginCredential::class, 'mfaAcceptedAmr'))->setValue($credential, ['mfa', 7, '', null, 'otp']);

        self::assertSame('deny', $credential->getLinkExistingAccount());
        self::assertSame('required', $credential->getMfaFirstFactor());
        self::assertSame('required', SocialCredentialPolicy::mfaRule($credential, $this->social(['amr' => ['mfa']])));
        self::assertSame(['mfa', 'otp'], $credential->getMfaAcceptedAmr());
        // A profile value that is not a policy (cannot come from the configuration tree) is read as deny.
        self::assertSame('deny', SocialCredentialPolicy::linkExistingAccount(null, $this->profile(['link_existing_account' => 'other'])));
    }

    public function testMfaPolicies(): void
    {
        $credential = new SocialLoginCredential();

        self::assertSame('required', SocialCredentialPolicy::mfaRule($credential->setMfaFirstFactorPolicy('required'), $this->social([])));
        self::assertSame('exempt', SocialCredentialPolicy::mfaRule($credential->setMfaFirstFactorPolicy('exempt'), $this->social(null)));
        $credential->setMfaFirstFactorPolicy('exempt_when_amr', ['mfa', 'hwk', 'mfa']);
        self::assertSame('exempt_when_amr', $credential->getMfaFirstFactor());
        self::assertSame(['mfa', 'hwk'], $credential->getMfaAcceptedAmr());
        self::assertSame('exempt', SocialCredentialPolicy::mfaRule($credential, $this->social(['amr' => ['pwd', 'mfa']])));
        self::assertSame('required', SocialCredentialPolicy::mfaRule($credential, $this->social(['amr' => ['pwd']])));
        self::assertNull($credential->setMfaFirstFactorPolicy(null)->getMfaFirstFactor());
        self::assertSame([], $credential->getMfaAcceptedAmr());
    }

    /**
     * SECURITY (spec 221 FR-012): exempt_when_amr reads the VALIDATED ID TOKEN's amr only. The userinfo answer is merged
     * into `raw` (it fills claims the token lacks), so a userinfo amr must never exempt the second step.
     */
    public function testAUserinfoAmrNeverExempts(): void
    {
        $credential   = (new SocialLoginCredential())->setMfaFirstFactorPolicy('exempt_when_amr', ['mfa']);
        $fromUserinfo = new SocialUserProfile('sub-1', 'ada@example.com', 'Ada', ['sub' => 'sub-1', 'amr' => ['mfa']], true, 'https://idp.example.com', ['sub' => 'sub-1']);
        $oauth2       = new SocialUserProfile('sub-1', 'ada@example.com', 'Ada', ['amr' => ['mfa']], true);

        self::assertSame('required', SocialCredentialPolicy::mfaRule($credential, $fromUserinfo));
        self::assertSame('required', SocialCredentialPolicy::mfaRule($credential, $oauth2), 'No ID token (OAuth2): never exempt.');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableAmr(): iterable
    {
        yield 'missing' => [null];
        yield 'a string, not a list' => ['mfa'];
        yield 'a map' => [['method' => 'mfa']];
        yield 'not strings' => [[1, true, null, ['mfa']]];
        yield 'empty strings' => [['']];
        yield 'case differs' => [['MFA']];
    }

    #[DataProvider('unusableAmr')]
    public function testUnusableAmrValuesNeverExempt(mixed $amr): void
    {
        self::assertFalse(SocialCredentialPolicy::amrAccepted($amr === null ? [] : ['amr' => $amr], ['mfa']));
    }

    public function testAnEmptyAcceptedListNeverExempts(): void
    {
        self::assertFalse(SocialCredentialPolicy::amrAccepted(['amr' => ['mfa']], []));
        self::assertFalse(SocialCredentialPolicy::amrAccepted(null, ['mfa']));
        self::assertTrue(SocialCredentialPolicy::amrAccepted(['amr' => ['mfa']], ['mfa']));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function invalidMfaPolicies(): iterable
    {
        yield 'unknown policy' => ['skip', []];
        yield 'amr list with exempt' => ['exempt', ['mfa']];
        yield 'amr list with required' => ['required', ['mfa']];
        yield 'amr list with null' => [null, ['mfa']];
        yield 'exempt_when_amr without values' => ['exempt_when_amr', []];
        yield 'too many values' => ['exempt_when_amr', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q']];
        yield 'empty value' => ['exempt_when_amr', ['']];
        yield 'space' => ['exempt_when_amr', ['m fa']];
        yield 'control character' => ['exempt_when_amr', ["mfa\n"]];
        yield 'too long' => ['exempt_when_amr', [str_repeat('a', 65)]];
    }

    /**
     * @param list<string> $amr
     */
    #[DataProvider('invalidMfaPolicies')]
    public function testInvalidMfaPoliciesAreRefused(?string $policy, array $amr): void
    {
        $credential = new SocialLoginCredential();

        try {
            $credential->setMfaFirstFactorPolicy($policy, $amr);
            self::fail('Expected a refusal.');
        } catch (InvalidArgumentException) {
            self::assertNull($credential->getMfaFirstFactor(), 'Nothing is stored on a refusal.');
        }
    }

    public function testMfaFirstFactorRuleAttribute(): void
    {
        $request = new Request();
        self::assertNull(MfaFirstFactor::ruleOf($request));
        self::assertNull(MfaFirstFactor::ruleOf(null));

        MfaFirstFactor::markRule($request, 'exempt');
        self::assertSame('exempt', MfaFirstFactor::ruleOf($request));
        MfaFirstFactor::markRule($request, 'required');
        self::assertSame('required', MfaFirstFactor::ruleOf($request));
        MfaFirstFactor::markRule($request, 'trusted');
        self::assertNull(MfaFirstFactor::ruleOf($request), 'Only required and exempt are rules of a first factor.');
        MfaFirstFactor::markRule($request, null);
        self::assertFalse($request->attributes->has(MfaFirstFactor::RULE_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function loginHints(): iterable
    {
        yield 'address' => ['ada@example.com', 'ada@example.com'];
        yield 'plus address' => ['ada+sso@example.co.uk', 'ada+sso@example.co.uk'];
        yield 'not an address' => ['ada', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'array' => [['ada@example.com'], null];
        yield 'new line' => ["ada@example.com\nX: y", null];
        yield 'space' => ['ada @example.com', null];
        // Audit ZCX-L1: dot-atom only (no quoted local part, no IP literal, no markup).
        yield 'quoted local part with markup' => ['"\\"><svg/onload=1>"@x.com', null];
        yield 'quoted local part' => ['"a&prompt=none"@x.com', null];
        yield 'IP literal' => ['a@[127.0.0.1]', null];
        yield 'comment' => ['a(b)@x.com', null];
        yield 'no dot in the host' => ['a@localhost', null];
        yield 'too long' => [str_repeat('a', 64) . '@' . str_repeat('b', 190) . '.com', null];
    }

    #[DataProvider('loginHints')]
    public function testLoginHint(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, LoginHint::normalize($value));
        self::assertSame($expected === null ? [] : ['login_hint' => $expected], LoginHint::params(is_string($value) ? $value : null));
    }

    /**
     * @param array<string, mixed> $socialLogin
     */
    private function profile(array $socialLogin = []): ProfileSettings
    {
        return ProfileRegistryFactory::single(TestUser::class, ['social_login' => $socialLogin + ['mode' => 'enabled']])->getDefault();
    }

    /**
     * @param array<string, mixed>|null $idTokenClaims
     */
    private function social(?array $idTokenClaims): SocialUserProfile
    {
        return new SocialUserProfile('sub-1', 'ada@example.com', 'Ada', [], true, 'https://idp.example.com', $idTokenClaims);
    }
}
