<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Saml\SamlProviderPolicy;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SamlProviderPolicy::class)]
final class SamlProviderPolicyTest extends TestCase
{
    public function testTheLinkPolicyIsTheRowsElseTheProfilesAndDenyWithoutARow(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class, ['social_login' => ['link_existing_account' => 'keep']])->getDefault();

        self::assertSame('deny', SamlProviderPolicy::linkExistingAccount(null, $profile));
        self::assertSame('keep', SamlProviderPolicy::linkExistingAccount(new SamlProvider(), $profile));
        self::assertSame('reset_password', SamlProviderPolicy::linkExistingAccount((new SamlProvider())->setLinkExistingAccount('reset_password'), $profile));
    }

    public function testTheMfaRuleFollowsTheAuthnContext(): void
    {
        $row = new SamlProvider();
        self::assertNull(SamlProviderPolicy::mfaRule($row, []));
        self::assertSame('exempt', SamlProviderPolicy::mfaRule($row->setMfaFirstFactorPolicy('exempt'), []));
        self::assertSame('required', SamlProviderPolicy::mfaRule($row->setMfaFirstFactorPolicy('required'), ['mfa']));

        $row->setMfaFirstFactorPolicy('exempt_when_authn_context', ['urn:mfa', 'urn:hw']);
        self::assertSame('exempt', SamlProviderPolicy::mfaRule($row, ['urn:password', 'urn:hw']));
        self::assertSame('required', SamlProviderPolicy::mfaRule($row, ['urn:password']));
        self::assertSame('required', SamlProviderPolicy::mfaRule($row, []));

        // An unknown stored value asks for the second step.
        (new ReflectionProperty(SamlProvider::class, 'mfaFirstFactor'))->setValue($row, 'sometimes');
        self::assertSame('required', SamlProviderPolicy::mfaRule($row, ['urn:hw']));
    }
}
