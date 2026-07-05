<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Support;

use Nowo\AuthKitBundle\Form\PasswordFieldConstraintResolver;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\PasswordRepeatedFieldBuilder;

/**
 * Shared password field resolvers for form and controller unit tests.
 */
final class PasswordFieldResolvers
{
    public static function typeResolver(): PasswordFieldTypeResolver
    {
        return new PasswordFieldTypeResolver();
    }

    public static function constraintResolver(?PasswordFieldTypeResolver $typeResolver = null): PasswordFieldConstraintResolver
    {
        return new PasswordFieldConstraintResolver($typeResolver ?? self::typeResolver());
    }

    public static function repeatedFieldBuilder(?PasswordFieldTypeResolver $typeResolver = null): PasswordRepeatedFieldBuilder
    {
        $typeResolver ??= self::typeResolver();

        return new PasswordRepeatedFieldBuilder($typeResolver, self::constraintResolver($typeResolver));
    }
}
