<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\OtpInputTypeResolver;
use PHPUnit\Framework\TestCase;

use function class_exists;

final class OtpInputTypeResolverTest extends TestCase
{
    public function testIsAvailableFollowsClassExists(): void
    {
        $available = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $missing   = new OtpInputTypeResolver(static fn (string $class): bool => false);

        self::assertTrue($available->isAvailable());
        self::assertFalse($missing->isAvailable());
        self::assertSame(OtpInputTypeResolver::OTP_TYPE, $available->resolveType());
        self::assertNull($missing->resolveType());
    }

    public function testDefaultClassExistsMatchesRuntime(): void
    {
        $resolver = new OtpInputTypeResolver();

        self::assertSame(class_exists(OtpInputTypeResolver::OTP_TYPE), $resolver->isAvailable());
    }

    public function testShouldUseForPasswordResetRequiresEnabledFlagAndBundle(): void
    {
        $available = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $missing   = new OtpInputTypeResolver(static fn (string $class): bool => false);

        self::assertTrue($available->shouldUseForPasswordReset([
            'enabled'             => true,
            'password_reset_code' => true,
        ]));
        self::assertNull($available->resolvePasswordResetCodeType([
            'enabled'             => false,
            'password_reset_code' => true,
        ]));
        self::assertNull($available->resolvePasswordResetCodeType([
            'enabled'             => true,
            'password_reset_code' => false,
        ]));
        self::assertNull($missing->resolvePasswordResetCodeType([
            'enabled'             => true,
            'password_reset_code' => true,
        ]));
    }

    public function testMfaCodeFollowsEnabledAndMfaCodeFlags(): void
    {
        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $on       = ['enabled' => true, 'password_reset_code' => false, 'mfa_code' => true, 'mfa_code_autosubmit' => true];

        self::assertTrue($resolver->wantsMfaCode($on));
        self::assertTrue($resolver->wantsMfaCodeAutosubmit($on));
        self::assertTrue($resolver->wantsMfaCode(['enabled' => true, 'password_reset_code' => true]), 'mfa_code defaults to true.');
        self::assertFalse($resolver->wantsMfaCodeAutosubmit(['enabled' => true, 'password_reset_code' => true]), 'Autosubmit defaults to false.');
        self::assertFalse($resolver->wantsMfaCode(['mfa_code' => true] + ['enabled' => false, 'password_reset_code' => true]));
        self::assertFalse($resolver->wantsMfaCode(['enabled' => true, 'password_reset_code' => true, 'mfa_code' => false]));
        self::assertFalse($resolver->wantsMfaCodeAutosubmit(['enabled' => true, 'password_reset_code' => true, 'mfa_code' => false, 'mfa_code_autosubmit' => true]), 'Autosubmit needs the OTP field.');
    }

    public function testResolveMfaCodeTypeFallsBackWithoutThePackage(): void
    {
        $available = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $missing   = new OtpInputTypeResolver(static fn (string $class): bool => false);

        self::assertSame(OtpInputTypeResolver::OTP_TYPE, $available->resolveMfaCodeType(true));
        self::assertNull($available->resolveMfaCodeType(false));
        self::assertNull($missing->resolveMfaCodeType(true));
    }

    public function testFieldOptionsMapCharset(): void
    {
        $resolver = new OtpInputTypeResolver();

        self::assertSame(
            ['length' => 6, 'numeric_only' => true, 'uppercase' => false],
            $resolver->fieldOptions(6, 'numeric'),
        );
        self::assertSame(
            ['length' => 8, 'numeric_only' => false, 'uppercase' => true],
            $resolver->fieldOptions(8, 'alphanumeric'),
        );
    }
}
