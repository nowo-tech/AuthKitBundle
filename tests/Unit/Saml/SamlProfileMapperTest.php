<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Nowo\AuthKitBundle\Saml\SamlAssertion;
use Nowo\AuthKitBundle\Saml\SamlFailure;
use Nowo\AuthKitBundle\Saml\SamlProfileMapper;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlProviders;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(SamlProfileMapper::class)]
#[CoversClass(SamlAssertion::class)]
final class SamlProfileMapperTest extends TestCase
{
    public function testTheMappedAttributesMakeTheProfile(): void
    {
        $profile = (new SamlProfileMapper())->map(SamlProviders::acme()->setEmailVerified(true), $this->assertion());

        self::assertSame('subject-1', $profile->id);
        self::assertSame('ada@example.com', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertSame('Ada Lovelace', $profile->name);
        self::assertSame('https://idp.example.com/saml', $profile->issuer);
        self::assertNull($profile->idTokenClaims);
        self::assertSame(['email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'groups' => ['staff']], $profile->raw);
        self::assertSame(['urn:ac:pwd'], $profile->samlAssertion['authn_context_class_refs'] ?? null);
        self::assertSame('_a1', $profile->samlAssertion['assertion_id'] ?? null);
    }

    public function testASubjectAttributeAndADisplayNameWinAndAnUnverifiedEmailStaysUnverified(): void
    {
        $row     = SamlProviders::acme()->setAttributeMapping(['subject' => 'oid', 'email' => 'mail', 'display_name' => 'cn', 'first_name' => 'givenName']);
        $profile = (new SamlProfileMapper())->map($row, $this->assertion(['oid' => ['', ' oid-7 '], 'cn' => ['Countess']], Constants::NAMEID_TRANSIENT));

        self::assertSame('oid-7', $profile->id);
        self::assertSame('Countess', $profile->name);
        self::assertNull($profile->emailVerified);
        self::assertSame([], $profile->raw['groups']);
    }

    public function testAnEmailNameIdIsTheEmailWithoutAMappedAttribute(): void
    {
        $row     = SamlProviders::acme()->setAttributeMapping([]);
        $profile = (new SamlProfileMapper())->map($row, new SamlAssertion('https://idp.example.com/saml', 'grace@example.com', Constants::NAMEID_EMAIL_ADDRESS, null, [], [], [], null));

        self::assertSame('grace@example.com', $profile->id);
        self::assertSame('grace@example.com', $profile->email);
        self::assertNull($profile->name);
    }

    public function testATransientOrMissingNameIdWithoutSubjectAttributeIsRefused(): void
    {
        $this->assertRefused(SamlFailure::UNUSABLE_SUBJECT, $this->assertion([], Constants::NAMEID_TRANSIENT));
        $this->assertRefused(SamlFailure::UNUSABLE_SUBJECT, new SamlAssertion('i', null, null, null, ['mail' => ['a@example.com']], [], [], null));
        $this->assertRefused(SamlFailure::UNUSABLE_SUBJECT, new SamlAssertion('i', " edge\u{00a0}", null, null, ['mail' => ['a@example.com']], [], [], null));
        $this->assertRefused(SamlFailure::UNUSABLE_SUBJECT, new SamlAssertion('i', str_repeat('a', 256), null, null, ['mail' => ['a@example.com']], [], [], null));
    }

    public function testAMissingOrInvalidEmailIsRefused(): void
    {
        $this->assertRefused(SamlFailure::MISSING_EMAIL, new SamlAssertion('i', 'subject-1', Constants::NAMEID_PERSISTENT, null, [], [], [], null));
        $this->assertRefused(SamlFailure::MISSING_EMAIL, new SamlAssertion('i', 'subject-1', null, null, ['mail' => ['not-an-email']], [], [], null));
        $this->assertRefused(SamlFailure::MISSING_EMAIL, new SamlAssertion('i', 'subject-1', null, null, ['mail' => [str_repeat('a', 250) . '@example.com']], [], [], null));
    }

    /**
     * @param array<string, list<string>> $extra
     */
    private function assertion(array $extra = [], ?string $format = Constants::NAMEID_PERSISTENT): SamlAssertion
    {
        return new SamlAssertion(
            'https://idp.example.com/saml',
            'subject-1',
            $format,
            '_s1',
            ['mail' => ['ada@example.com'], 'sn' => ['Lovelace'], 'groups' => ['staff', ' ']] + $extra,
            ['givenName' => ['Ada']],
            ['urn:ac:pwd'],
            '_a1',
        );
    }

    private function assertRefused(string $reason, SamlAssertion $assertion): void
    {
        try {
            (new SamlProfileMapper())->map(SamlProviders::acme(), $assertion);
            self::fail('Mapped.');
        } catch (SamlFailure $failure) {
            self::assertSame($reason, $failure->reason);
        }
    }
}
