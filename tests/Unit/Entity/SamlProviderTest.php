<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Entity;

use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

#[CoversClass(SamlProvider::class)]
final class SamlProviderTest extends TestCase
{
    public function testANewRowIsDisabledAndIncomplete(): void
    {
        $row = new SamlProvider();

        self::assertNull($row->getId());
        self::assertFalse($row->isEnabled());
        self::assertFalse($row->isComplete());
        self::assertSame([], $row->getIdpCertificates());
        self::assertSame([], $row->getAttributeMapping());
        self::assertNull($row->getLinkExistingAccount());
        self::assertNull($row->getMfaFirstFactor());
        self::assertSame([], $row->getMfaAcceptedAuthnContexts());
        self::assertFalse($row->isEmailVerified());
        self::assertFalse($row->isWantEncryptedAssertions());
        self::assertSame('saml:acme', SamlProvider::accountProvider('acme'));
        self::assertEquals($row->getCreatedAt(), $row->getUpdatedAt());
    }

    public function testACompleteRow(): void
    {
        $idp  = SamlKeys::pair('idp');
        $next = SamlKeys::pair('idp-next');
        $sp   = SamlKeys::pair('sp');
        $row  = (new SamlProvider())
            ->setProvider('org-1_a')
            ->setScope('tenant-1')
            ->setLabel('  Acme  ')
            ->setEnabled(true)
            ->setIdpEntityId('https://idp.example.com/saml')
            ->setIdpSsoUrl('https://idp.example.com/sso?tenant=1')
            ->setIdpCertificates($idp['cert'], $next['cert'])
            ->setSpEntityId('urn:sp:acme')
            ->setSpKeyPair($sp['key'], $sp['cert'])
            ->setNameIdFormat('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent')
            ->setAttributeMapping(['email' => ' mail ', 'groups' => null, 'subject' => ''])
            ->setEmailVerified(true)
            ->setWantEncryptedAssertions(true);

        self::assertTrue($row->isComplete());
        self::assertSame('org-1_a', $row->getProvider());
        self::assertSame('tenant-1', $row->getScope());
        self::assertSame('Acme', $row->getLabel());
        self::assertSame('https://idp.example.com/saml', $row->getIdpEntityId());
        self::assertSame('https://idp.example.com/sso?tenant=1', $row->getIdpSsoUrl());
        self::assertCount(2, $row->getIdpCertificates());
        self::assertSame($row->getIdpCertificate(), $row->getIdpCertificates()[0]);
        self::assertSame($row->getIdpNextCertificate(), $row->getIdpCertificates()[1]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->getIdpCertificateSha256());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->getIdpNextCertificateSha256());
        self::assertNotNull($row->getIdpCertificateNotBefore());
        self::assertNotNull($row->getIdpCertificateNotAfter());
        self::assertNotNull($row->getIdpNextCertificateNotBefore());
        self::assertNotNull($row->getIdpNextCertificateNotAfter());
        self::assertSame('urn:sp:acme', $row->getSpEntityId());
        self::assertSame($sp['key'], $row->getSpPrivateKey());
        self::assertStringContainsString('BEGIN CERTIFICATE', (string) $row->getSpCertificate());
        self::assertNotNull($row->getSpCertificateNotAfter());
        self::assertSame('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', $row->getNameIdFormat());
        self::assertSame(['email' => 'mail'], $row->getAttributeMapping());
        self::assertTrue($row->isEmailVerified());
        self::assertTrue($row->isWantEncryptedAssertions());

        $row->setIdpCertificates($idp['cert'], ' ')->setScope(null)->setSpEntityId(null)->setNameIdFormat(null);
        self::assertCount(1, $row->getIdpCertificates());
        self::assertNull($row->getIdpNextCertificateSha256());
        self::assertNull($row->getScope());
        self::assertNull($row->getSpEntityId());
        self::assertNull($row->getNameIdFormat());
    }

    public function testTheSpKeyPairRotates(): void
    {
        $sp   = SamlKeys::pair('sp');
        $next = SamlKeys::pair('sp-next');
        $row  = (new SamlProvider())->setSpKeyPair($sp['key'], $sp['cert']);
        $at   = $row->getUpdatedAt();
        // The same pair again (as the encryption layer writes it back) is not a change.
        $row->setSpKeyPair($sp['key'], $sp['cert']);
        self::assertSame($at, $row->getUpdatedAt());

        $row->setNextSpKeyPair($next['key'], $next['cert']);
        self::assertSame($next['key'], $row->getSpNextPrivateKey());
        self::assertNotNull($row->getSpNextCertificate());
        self::assertNotNull($row->getSpNextCertificateNotAfter());

        $row->promoteNextSpKeyPair();
        self::assertSame($next['key'], $row->getSpPrivateKey());
        self::assertNull($row->getSpNextPrivateKey());
        self::assertNull($row->getSpNextCertificate());
        self::assertNull($row->getSpNextCertificateNotAfter());

        $this->expectException(LogicException::class);
        $row->promoteNextSpKeyPair();
    }

    public function testTheNextSlotIsClearedWithTwoNullsOnly(): void
    {
        $next = SamlKeys::pair('sp-next');
        $row  = (new SamlProvider())->setNextSpKeyPair($next['key'], $next['cert'])->setNextSpKeyPair(null, null);
        self::assertNull($row->getSpNextPrivateKey());

        $this->expectException(InvalidArgumentException::class);
        $row->setNextSpKeyPair($next['key'], null);
    }

    public function testAKeyOfAnotherCertificateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SamlProvider())->setSpKeyPair(SamlKeys::pair('sp')['key'], SamlKeys::pair('idp')['cert']);
    }

    public function testTheSameIdpCertificateTwiceIsRefused(): void
    {
        $cert = SamlKeys::pair('idp')['cert'];
        $this->expectException(InvalidArgumentException::class);
        (new SamlProvider())->setIdpCertificates($cert, $cert);
    }

    /**
     * @return iterable<string, array{callable(SamlProvider): mixed}>
     */
    public static function refused(): iterable
    {
        yield 'key with a colon' => [static fn (SamlProvider $row): mixed => $row->setProvider('saml:acme')];
        yield 'empty key' => [static fn (SamlProvider $row): mixed => $row->setProvider('')];
        yield 'key too long' => [static fn (SamlProvider $row): mixed => $row->setProvider(str_repeat('a', 60))];
        yield 'empty scope' => [static fn (SamlProvider $row): mixed => $row->setScope('')];
        yield 'scope too long' => [static fn (SamlProvider $row): mixed => $row->setScope(str_repeat('a', 192))];
        yield 'label too long' => [static fn (SamlProvider $row): mixed => $row->setLabel(str_repeat('a', 129))];
        yield 'entity id with space' => [static fn (SamlProvider $row): mixed => $row->setIdpEntityId('https://idp example')];
        yield 'empty entity id' => [static fn (SamlProvider $row): mixed => $row->setIdpEntityId('')];
        yield 'sp entity id too long' => [static fn (SamlProvider $row): mixed => $row->setSpEntityId(str_repeat('a', 513))];
        yield 'http sso url' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl('http://idp.example.com/sso')];
        yield 'sso url with credentials' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl('https://user@idp.example.com/sso')];
        yield 'sso url with fragment' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl('https://idp.example.com/sso#x')];
        yield 'sso url without host' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl('https:///sso')];
        yield 'sso url with a new line' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl("https://idp.example.com/sso\n")];
        yield 'sso url too long' => [static fn (SamlProvider $row): mixed => $row->setIdpSsoUrl('https://idp.example.com/' . str_repeat('a', 512))];
        yield 'name id format' => [static fn (SamlProvider $row): mixed => $row->setNameIdFormat('persistent')];
        yield 'mapping key' => [static fn (SamlProvider $row): mixed => $row->setAttributeMapping(['role' => 'x'])];
        yield 'mapping name too long' => [static fn (SamlProvider $row): mixed => $row->setAttributeMapping(['email' => str_repeat('a', 256)])];
        yield 'mapping name with control' => [static fn (SamlProvider $row): mixed => $row->setAttributeMapping(['email' => "ma\x01il"])];
        yield 'link policy' => [static fn (SamlProvider $row): mixed => $row->setLinkExistingAccount('merge')];
        yield 'mfa policy' => [static fn (SamlProvider $row): mixed => $row->setMfaFirstFactorPolicy('never')];
        yield 'contexts without the policy' => [static fn (SamlProvider $row): mixed => $row->setMfaFirstFactorPolicy('exempt', ['urn:x'])];
        yield 'policy without contexts' => [static fn (SamlProvider $row): mixed => $row->setMfaFirstFactorPolicy('exempt_when_authn_context')];
        yield 'too many contexts' => [static fn (SamlProvider $row): mixed => $row->setMfaFirstFactorPolicy('exempt_when_authn_context', array_map(static fn (int $i): string => 'urn:' . $i, range(1, 17)))];
        yield 'context with space' => [static fn (SamlProvider $row): mixed => $row->setMfaFirstFactorPolicy('exempt_when_authn_context', ['urn: x'])];
    }

    /**
     * @param callable(SamlProvider): mixed $change
     */
    #[DataProvider('refused')]
    public function testInvalidValuesAreRefused(callable $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        $change(new SamlProvider());
    }

    public function testPoliciesAndUnknownStoredValuesFailClosed(): void
    {
        $row = (new SamlProvider())->setLinkExistingAccount('keep')->setMfaFirstFactorPolicy('exempt_when_authn_context', ['urn:a', 'urn:a', 'urn:b']);
        self::assertSame('keep', $row->getLinkExistingAccount());
        self::assertSame('exempt_when_authn_context', $row->getMfaFirstFactor());
        self::assertSame(['urn:a', 'urn:b'], $row->getMfaAcceptedAuthnContexts());

        (new ReflectionProperty(SamlProvider::class, 'linkExistingAccount'))->setValue($row, 'merge');
        (new ReflectionProperty(SamlProvider::class, 'mfaFirstFactor'))->setValue($row, 'never');
        (new ReflectionProperty(SamlProvider::class, 'mfaAcceptedAuthnContexts'))->setValue($row, ['urn:a', 3, '']);
        (new ReflectionProperty(SamlProvider::class, 'attributeMapping'))->setValue($row, ['email' => 'mail', 'role' => 'x', 'groups' => 7]);
        self::assertSame('deny', $row->getLinkExistingAccount());
        self::assertSame('required', $row->getMfaFirstFactor());
        self::assertSame(['urn:a'], $row->getMfaAcceptedAuthnContexts());
        self::assertSame(['email' => 'mail'], $row->getAttributeMapping());

        $row->setMfaFirstFactorPolicy(null);
        self::assertNull($row->getMfaFirstFactor());
        self::assertSame([], $row->getMfaAcceptedAuthnContexts());
        (new ReflectionProperty(SamlProvider::class, 'attributeMapping'))->setValue($row, null);
        self::assertSame([], $row->getAttributeMapping());
    }
}
