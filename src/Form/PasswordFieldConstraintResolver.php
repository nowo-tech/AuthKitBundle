<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Builds password validation constraints for registration and reset flows.
 */
final class PasswordFieldConstraintResolver
{
    private const STRENGTH_CONSTRAINT = 'Nowo\PasswordStrengthBundle\Validator\PasswordStrength';

    public function __construct(
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
    ) {
    }

    /**
     * @return list<Constraint>
     */
    public function newPasswordConstraints(string $requiredMessage, string $minLengthMessage): array
    {
        $constraints = [new NotBlank(message: $requiredMessage)];

        if ($this->passwordFieldTypeResolver->usesPasswordStrengthForNewPassword()) {
            $constraints[] = $this->createStrengthConstraint(
                $this->passwordFieldTypeResolver->passwordStrengthConstraintOptions(),
            );

            return $constraints;
        }

        $constraints[] = new Length(min: 6, minMessage: $minLengthMessage);

        return $constraints;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createStrengthConstraint(array $options): PasswordStrength
    {
        $constraint             = new (self::STRENGTH_CONSTRAINT)();
        $constraint->policyMode = $options['policyMode'] === 'conditions' ? 'conditions' : 'level';
        $constraint->level      = $options['level'];

        return $constraint;
    }
}
