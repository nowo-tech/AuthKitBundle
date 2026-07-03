<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\PasswordFieldConstraintResolver;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\PasswordStrengthBundle\Form\PasswordStrengthType;
use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PasswordFieldConstraintResolverTest extends TestCase
{
    public function testUsesLengthConstraintWhenPasswordStrengthIsDisabled(): void
    {
        $resolver = new PasswordFieldConstraintResolver(new PasswordFieldTypeResolver());

        $constraints = $resolver->newPasswordConstraints('required.message', 'min.message');

        self::assertCount(2, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertSame('required.message', $constraints[0]->message);
        self::assertInstanceOf(Length::class, $constraints[1]);
        self::assertSame('min.message', $constraints[1]->minMessage);
    }

    public function testUsesPasswordStrengthConstraintWhenEnabled(): void
    {
        $typeResolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'strong', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => $class === PasswordStrengthType::class,
        );
        $resolver = new PasswordFieldConstraintResolver($typeResolver);

        $constraints = $resolver->newPasswordConstraints('required.message', 'min.message');

        self::assertCount(2, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(PasswordStrength::class, $constraints[1]);
        self::assertSame('level', $constraints[1]->policyMode);
        self::assertSame('strong', $constraints[1]->level);
    }
}
