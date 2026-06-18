<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
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
    }
}
