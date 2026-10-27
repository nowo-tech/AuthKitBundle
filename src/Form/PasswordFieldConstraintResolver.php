<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * Builds password validation constraints for registration and reset flows.
 */
final class PasswordFieldConstraintResolver
{
    public const COMPROMISED_MESSAGE = 'validation.password_compromised';

    private const STRENGTH_CONSTRAINT = 'Nowo\PasswordStrengthBundle\Validator\PasswordStrength';

    /**
     * @param array{min_length?: int, not_compromised?: bool} $passwordPolicy
     */
    public function __construct(
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
        private readonly array $passwordPolicy = [],
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

        // SEC-004 L5: 12 characters by default (was 6), optional breach check.
        $constraints[] = new Length(
            min: max(1, $this->passwordPolicy['min_length'] ?? Configuration::DEFAULT_PASSWORD_MIN_LENGTH),
            max: 4096,
            minMessage: $minLengthMessage,
        );
        if ($this->passwordPolicy['not_compromised'] ?? false) {
            $constraints[] = new NotCompromisedPassword(message: self::COMPROMISED_MESSAGE);
        }

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
