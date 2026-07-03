<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Closure;
use Symfony\Component\Form\Extension\Core\Type\PasswordType as SymfonyPasswordType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Resolves the password form field type based on installed packages and bundle configuration.
 *
 * Login fields use toggle or Symfony PasswordType only.
 * Registration and password-reset fields may use PasswordStrengthType when enabled.
 */
final class PasswordFieldTypeResolver
{
    private const TOGGLE_PASSWORD_TYPE = 'Nowo\PasswordToggleBundle\Form\Type\PasswordType';

    private const STRENGTH_PASSWORD_TYPE = 'Nowo\PasswordStrengthBundle\Form\PasswordStrengthType';

    /**
     * @param class-string<FormTypeInterface>|null $passwordFieldType Override for tests; null resolves at runtime
     * @param array{enabled: bool, level: string, policy_mode: string} $passwordStrength
     * @param class-string<FormTypeInterface> $togglePasswordType Toggle bundle password type to detect
     * @param Closure(class-string<FormTypeInterface>): bool|null $toggleTypeExists Override for tests; null uses class_exists()
     * @param Closure(class-string<FormTypeInterface>): bool|null $strengthTypeExists Override for tests; null uses class_exists()
     */
    public function __construct(
        private readonly ?string $passwordFieldType = null,
        private readonly array $passwordStrength = ['enabled' => false, 'level' => 'medium', 'policy_mode' => 'level'],
        private readonly string $togglePasswordType = self::TOGGLE_PASSWORD_TYPE,
        private readonly ?Closure $toggleTypeExists = null,
        private readonly ?Closure $strengthTypeExists = null,
    ) {
    }

    /**
     * @return class-string<FormTypeInterface>
     */
    public function resolve(): string
    {
        return $this->resolveBasicPasswordType();
    }

    /**
     * @return class-string<FormTypeInterface>
     */
    public function resolveForNewPassword(): string
    {
        if ($this->passwordFieldType !== null) {
            return $this->passwordFieldType;
        }

        if ($this->usesPasswordStrengthForNewPassword()) {
            return self::STRENGTH_PASSWORD_TYPE;
        }

        return $this->resolveBasicPasswordType();
    }

    public function usesPasswordStrengthForNewPassword(): bool
    {
        if ($this->passwordFieldType !== null || !$this->passwordStrength['enabled']) {
            return false;
        }

        return $this->strengthTypeIsAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    public function newPasswordFieldOptions(): array
    {
        if (!$this->usesPasswordStrengthForNewPassword()) {
            return [];
        }

        return [
            'policy_mode' => $this->passwordStrength['policy_mode'],
            'level'       => $this->passwordStrength['level'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function passwordStrengthConstraintOptions(): array
    {
        return [
            'policyMode' => $this->passwordStrength['policy_mode'],
            'level'      => $this->passwordStrength['level'],
        ];
    }

    /**
     * @return class-string<FormTypeInterface>
     */
    private function resolveBasicPasswordType(): string
    {
        if ($this->passwordFieldType !== null) {
            return $this->passwordFieldType;
        }

        return $this->toggleTypeIsAvailable()
            ? $this->togglePasswordType
            : SymfonyPasswordType::class;
    }

    private function toggleTypeIsAvailable(): bool
    {
        if ($this->toggleTypeExists instanceof Closure) {
            return ($this->toggleTypeExists)($this->togglePasswordType);
        }

        return class_exists($this->togglePasswordType);
    }

    private function strengthTypeIsAvailable(): bool
    {
        if ($this->strengthTypeExists instanceof Closure) {
            return ($this->strengthTypeExists)(self::STRENGTH_PASSWORD_TYPE);
        }

        return class_exists(self::STRENGTH_PASSWORD_TYPE);
    }
}
