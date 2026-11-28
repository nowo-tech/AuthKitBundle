<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Saml\SamlAssertion;
use Nowo\AuthKitBundle\Saml\SamlFailure;
use Nowo\AuthKitBundle\Saml\SamlResponseValidator;
use Nowo\AuthKitBundle\Saml\SamlSettingsFactory;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlKeys;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlProviders;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlResponseBuilder;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function base64_encode;
use function str_repeat;

/**
 * 2.10.0: every check of a SAML response against fixtures signed at test time with throwaway keys.
 */
#[CoversClass(SamlResponseValidator::class)]
#[CoversClass(SamlSettingsFactory::class)]
final class SamlResponseValidatorTest extends TestCase
{
    private const ACS    = 'https://sp.example.com/login/saml/acme/acs';
    private const ENTITY = 'https://sp.example.com/saml/acme/metadata';

    public function testASignedAssertionPasses(): void
    {
        $assertion = $this->validate($this->builder());

        self::assertSame(SamlResponseBuilder::IDP_ENTITY_ID, $assertion->issuer);
        self::assertSame('subject-1', $assertion->nameId);
        self::assertSame(Constants::NAMEID_PERSISTENT, $assertion->nameIdFormat);
        self::assertSame('_session-1', $assertion->sessionIndex);
        self::assertSame(['ada@example.com'], $assertion->values('mail'));
        self::assertSame(['staff', 'admins'], $assertion->values('groups-friendly'));
        self::assertSame([], $assertion->values('missing'));
        self::assertSame(['urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'], $assertion->authnContextClassRefs);
        self::assertNotNull($assertion->assertionId);
    }

    public function testASignedResponseAndBothSignedPass(): void
    {
        foreach (['response', 'both'] as $sign) {
            $builder       = $this->builder();
            $builder->sign = $sign;
            self::assertSame('subject-1', $this->validate($builder)->nameId, $sign);
        }
    }

    public function testAnEncryptedAssertionIsDecryptedWithTheCurrentOrTheNextKey(): void
    {
        $builder                        = $this->builder();
        $builder->encryptForCertificate = SamlKeys::pair('sp')['cert'];
        self::assertSame('subject-1', $this->validate($builder)->nameId);

        $next                           = SamlKeys::pair('sp-next');
        $provider                       = SamlProviders::acme()->setNextSpKeyPair($next['key'], $next['cert']);
        $builder->encryptForCertificate = $next['cert'];
        self::assertSame('subject-1', $this->validate($builder, $provider)->nameId);

        // Encrypted for a key the row does not hold: refused.
        $builder->encryptForCertificate = SamlKeys::pair('stranger')['cert'];
        $this->assertRefused(SamlFailure::DECRYPTION, $builder, $provider);
    }

    public function testThesecondIdpCertificateIsAcceptedDuringARollover(): void
    {
        $builder          = $this->builder();
        $builder->idpPair = SamlKeys::pair('idp-next');
        $this->assertRefused(SamlFailure::SIGNATURE, $builder);

        $provider = SamlProviders::acme()->setIdpCertificates(SamlKeys::pair('idp')['cert'], SamlKeys::pair('idp-next')['cert']);
        self::assertSame('subject-1', $this->validate($builder, $provider)->nameId);
    }

    public function testEncryptionCanBeRequired(): void
    {
        $this->assertRefused(SamlFailure::ENCRYPTION_REQUIRED, $this->builder(), SamlProviders::acme()->setWantEncryptedAssertions(true));
    }

    /**
     * @return iterable<string, array{string, callable(SamlResponseBuilder): void}>
     */
    public static function refusals(): iterable
    {
        yield 'unsigned' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void { $b->sign = 'none'; }];
        yield 'tampered after signing' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void { $b->tamper = true; }];
        yield 'signed by another key' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void { $b->idpPair = SamlKeys::pair('attacker'); }];
        yield 'wrapped (a second assertion)' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void { $b->wrap = true; }];
        yield 'wrapped signed response' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void {
            $b->wrap = true;
            $b->sign = 'response';
        }];
        yield 'sha-1 digest' => [SamlFailure::SIGNATURE, static function (SamlResponseBuilder $b): void { $b->sha1Digest = true; }];
        yield 'another request' => [SamlFailure::IN_RESPONSE_TO, static function (SamlResponseBuilder $b): void { $b->responseInResponseTo = '_other'; }];
        yield 'unsolicited (no InResponseTo)' => [SamlFailure::IN_RESPONSE_TO, static function (SamlResponseBuilder $b): void { $b->responseInResponseTo = ''; }];
        yield 'subject for another request' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->subjectInResponseTo = '_other'; }];
        yield 'subject without InResponseTo' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->subjectInResponseTo = ''; }];
        yield 'other destination' => [SamlFailure::DESTINATION, static function (SamlResponseBuilder $b): void { $b->destination = 'https://evil.example.com/acs'; }];
        yield 'longer destination' => [SamlFailure::DESTINATION, static function (SamlResponseBuilder $b): void { $b->destination = self::ACS . '/x'; }];
        yield 'no destination' => [SamlFailure::DESTINATION, static function (SamlResponseBuilder $b): void { $b->omitDestination = true; }];
        yield 'other recipient' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->recipient = 'https://evil.example.com/acs'; }];
        yield 'prefix recipient' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->recipient = self::ACS . '-evil'; }];
        yield 'other audience' => [SamlFailure::AUDIENCE, static function (SamlResponseBuilder $b): void { $b->audience = 'https://other-sp.example.com'; }];
        yield 'no audience' => [SamlFailure::AUDIENCE, static function (SamlResponseBuilder $b): void { $b->omitAudience = true; }];
        yield 'other assertion issuer' => [SamlFailure::ISSUER, static function (SamlResponseBuilder $b): void { $b->assertionIssuer = 'https://evil.example.com'; }];
        yield 'other response issuer' => [SamlFailure::ISSUER, static function (SamlResponseBuilder $b): void { $b->responseIssuer = 'https://evil.example.com'; }];
        yield 'expired' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void {
            $b->notOnOrAfter = -600;
            $b->notBefore    = -900;
        }];
        yield 'not yet valid' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void { $b->notBefore = 600; }];
        yield 'idp session ended' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void { $b->sessionNotOnOrAfter = -600; }];
        // Accepted by the library (prefix / substring / missing / 180 s drift), refused by AuthKit's own checks.
        yield 'shorter destination' => [SamlFailure::DESTINATION, static function (SamlResponseBuilder $b): void { $b->destination = 'https://sp.example.com/login'; }];
        yield 'subject without NotOnOrAfter' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->omitSubjectNotOnOrAfter = true; }];
        yield 'subject not yet valid by 120 s' => [SamlFailure::SUBJECT_CONFIRMATION, static function (SamlResponseBuilder $b): void { $b->subjectNotBefore = 120; }];
        yield 'conditions not yet valid by 120 s' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void { $b->notBefore = 120; }];
        yield 'conditions expired by 120 s' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void { $b->conditionsNotOnOrAfter = -120; }];
        yield 'idp session ended 120 s ago' => [SamlFailure::TIMING, static function (SamlResponseBuilder $b): void { $b->sessionNotOnOrAfter = -120; }];
        yield 'a restriction to another sp' => [SamlFailure::AUDIENCE, static function (SamlResponseBuilder $b): void { $b->foreignAudienceRestriction = true; }];
        yield 'assertion outside the signed response' => [SamlFailure::MALFORMED, static function (SamlResponseBuilder $b): void {
            $b->sign             = 'response';
            $b->nestInExtensions = true;
        }];
        yield 'duplicate attribute' => [SamlFailure::MALFORMED, static function (SamlResponseBuilder $b): void { $b->duplicateAttribute = true; }];
        yield 'idp status' => [SamlFailure::STATUS, static function (SamlResponseBuilder $b): void { $b->status = 'urn:oasis:names:tc:SAML:2.0:status:Requester'; }];
    }

    /**
     * @param callable(SamlResponseBuilder): void $change
     */
    #[DataProvider('refusals')]
    public function testARefusal(string $reason, callable $change): void
    {
        $builder = $this->builder();
        $change($builder);
        $this->assertRefused($reason, $builder);
    }

    public function testTheConfiguredSkewIsAppliedOnTopOfTheLibrary(): void
    {
        $builder               = $this->builder();
        $builder->notOnOrAfter = 30;
        // 60 s later the assertion is 30 s past its end: inside a 60 s skew, outside a 10 s one.
        $clock = new MockClock('+60 seconds');
        self::assertSame('subject-1', $this->validate($builder, null, 60, $clock)->nameId);
        $this->assertRefused(SamlFailure::SUBJECT_CONFIRMATION, $builder, null, 10, $clock);
    }

    public function testSettingsTheLibraryRefusesMakeTheProviderUnavailable(): void
    {
        try {
            (new SamlResponseValidator(new SamlSettingsFactory(), new MockClock()))->validate(SamlProviders::acme(), $this->builder()->build(), '_request-1', self::ACS, '', 60);
            self::fail('Accepted without an SP entity id.');
        } catch (SamlFailure $failure) {
            self::assertSame(SamlFailure::UNAVAILABLE, $failure->reason);
        }
    }

    public function testAnAssertionWithoutNameIdOrAttributesStillPasses(): void
    {
        $builder               = $this->builder();
        $builder->nameId       = null;
        $builder->attributes   = [];
        $builder->authnContext = '';
        $assertion             = $this->validate($builder);
        self::assertNull($assertion->nameId);
        self::assertNull($assertion->nameIdFormat);
        self::assertSame([], $assertion->attributes);
        self::assertSame([], $assertion->authnContextClassRefs);
    }

    public function testGarbageIsMalformed(): void
    {
        $validator = new SamlResponseValidator(new SamlSettingsFactory(), new MockClock());
        foreach (['', 'not base64 !', str_repeat('A', SamlResponseValidator::MAX_RESPONSE_BYTES + 4), base64_encode('<not-xml'), base64_encode('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><x>&e;</x>')] as $value) {
            try {
                $validator->validate(SamlProviders::acme(), $value, '_request-1', self::ACS, self::ENTITY, 60);
                self::fail('Accepted garbage.');
            } catch (SamlFailure $failure) {
                self::assertContains($failure->reason, [SamlFailure::MALFORMED, SamlFailure::SIGNATURE]);
            }
        }
    }

    private function builder(): SamlResponseBuilder
    {
        return new SamlResponseBuilder('_request-1', self::ACS, self::ENTITY);
    }

    private function validate(SamlResponseBuilder $builder, ?SamlProvider $provider = null, int $skew = 60, ?MockClock $clock = null): SamlAssertion
    {
        return (new SamlResponseValidator(new SamlSettingsFactory(), $clock ?? new MockClock()))
            ->validate($provider ?? SamlProviders::acme(), $builder->build(), '_request-1', self::ACS, self::ENTITY, $skew);
    }

    private function assertRefused(string $reason, SamlResponseBuilder $builder, ?SamlProvider $provider = null, int $skew = 60, ?MockClock $clock = null): void
    {
        try {
            $this->validate($builder, $provider, $skew, $clock);
            self::fail('The response was accepted; expected ' . $reason . '.');
        } catch (SamlFailure $failure) {
            self::assertSame($reason, $failure->reason, $failure->getMessage());
        }
    }
}
