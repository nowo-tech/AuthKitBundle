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
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

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
        // SEC-004 L5: 12 characters by default (was 6).
        self::assertSame(12, $constraints[1]->min);
        self::assertCount(2, $constraints);
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

    public function testPasswordPolicyMinLengthAndBreachCheck(): void
    {
        $constraints = (new PasswordFieldConstraintResolver(new PasswordFieldTypeResolver(), ['min_length' => 16, 'not_compromised' => true]))
            ->newPasswordConstraints('required.message', 'min.message');

        self::assertInstanceOf(Length::class, $constraints[1]);
        self::assertSame(16, $constraints[1]->min);
        self::assertInstanceOf(NotCompromisedPassword::class, $constraints[2]);
        self::assertSame(PasswordFieldConstraintResolver::COMPROMISED_MESSAGE, $constraints[2]->message);
    }
}
