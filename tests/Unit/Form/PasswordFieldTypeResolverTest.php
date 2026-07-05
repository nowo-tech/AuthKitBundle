<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\PasswordStrengthBundle\Form\PasswordStrengthType;
use Nowo\PasswordToggleBundle\Form\Type\PasswordType as TogglePasswordType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\PasswordType as SymfonyPasswordType;

final class PasswordFieldTypeResolverTest extends TestCase
{
    public function testResolveUsesTogglePasswordTypeWhenBundleIsInstalled(): void
    {
        $resolver = new PasswordFieldTypeResolver();

        self::assertSame(TogglePasswordType::class, $resolver->resolve());
    }

    public function testResolveUsesSymfonyPasswordTypeWhenToggleBundleIsUnavailable(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            togglePasswordType: TogglePasswordType::class,
            toggleTypeExists: static fn (string $class): bool => false,
        );

        self::assertSame(SymfonyPasswordType::class, $resolver->resolve());
    }

    public function testResolveUsesExplicitOverride(): void
    {
        $resolver = new PasswordFieldTypeResolver(SymfonyPasswordType::class);

        self::assertSame(SymfonyPasswordType::class, $resolver->resolve());
        self::assertSame(SymfonyPasswordType::class, $resolver->resolveForNewPassword());
    }

    public function testResolveForNewPasswordUsesStrengthTypeWhenEnabled(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => $class === PasswordStrengthType::class,
        );

        self::assertSame(PasswordStrengthType::class, $resolver->resolveForNewPassword());
        self::assertTrue($resolver->usesPasswordStrengthForNewPassword());
        self::assertSame(TogglePasswordType::class, $resolver->resolve());
    }

    public function testResolveForNewPasswordFallsBackWhenStrengthBundleIsUnavailable(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => false,
        );

        self::assertSame(TogglePasswordType::class, $resolver->resolveForNewPassword());
        self::assertFalse($resolver->usesPasswordStrengthForNewPassword());
    }

    public function testNewPasswordFieldOptionsIncludePolicyWhenStrengthIsEnabled(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'strong', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => $class === PasswordStrengthType::class,
        );

        self::assertSame([
            'policy_mode' => 'level',
            'level'       => 'strong',
        ], $resolver->newPasswordFieldOptions());
    }

    public function testNewPasswordFieldOptionsReturnsEmptyWhenStrengthDisabled(): void
    {
        $resolver = new PasswordFieldTypeResolver();

        self::assertSame([], $resolver->newPasswordFieldOptions());
    }

    public function testPasswordStrengthConstraintOptions(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => false, 'level' => 'strong', 'policy_mode' => 'conditions'],
        );

        self::assertSame([
            'policyMode' => 'conditions',
            'level'      => 'strong',
        ], $resolver->passwordStrengthConstraintOptions());
    }

    public function testUsesPasswordStrengthChecksAvailabilityViaClassExists(): void
    {
        $resolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'],
        );

        $strengthAvailable = class_exists(PasswordStrengthType::class);

        self::assertSame($strengthAvailable, $resolver->usesPasswordStrengthForNewPassword());
        self::assertSame(
            $strengthAvailable ? PasswordStrengthType::class : TogglePasswordType::class,
            $resolver->resolveForNewPassword(),
        );
    }
}
