<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Support;

use Nowo\AuthKitBundle\Form\PasswordFieldConstraintResolver;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;

/**
 * Shared password field resolvers for form and controller unit tests.
 */
final class PasswordFieldResolvers
{
    public static function typeResolver(): PasswordFieldTypeResolver
    {
        return new PasswordFieldTypeResolver();
    }

    public static function constraintResolver(): PasswordFieldConstraintResolver
    {
        return new PasswordFieldConstraintResolver(self::typeResolver());
    }
}
