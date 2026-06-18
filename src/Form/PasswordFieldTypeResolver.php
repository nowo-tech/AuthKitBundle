<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Closure;
use Symfony\Component\Form\Extension\Core\Type\PasswordType as SymfonyPasswordType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Resolves the password form field type based on installed packages.
 */
final class PasswordFieldTypeResolver
{
    private const TOGGLE_PASSWORD_TYPE = 'Nowo\PasswordToggleBundle\Form\Type\PasswordType';

    /**
     * @param class-string<FormTypeInterface>|null $passwordFieldType Override for tests; null resolves at runtime
     * @param class-string<FormTypeInterface> $togglePasswordType Toggle bundle password type to detect
     * @param Closure(class-string<FormTypeInterface>): bool|null $toggleTypeExists Override for tests; null uses class_exists()
     */
    public function __construct(
        private readonly ?string $passwordFieldType = null,
        private readonly string $togglePasswordType = self::TOGGLE_PASSWORD_TYPE,
        private readonly ?Closure $toggleTypeExists = null,
    ) {
    }

    /**
     * @return class-string<FormTypeInterface>
     */
    public function resolve(): string
    {
        if ($this->passwordFieldType !== null) {
            return $this->passwordFieldType;
        }

        $exists = $this->toggleTypeExists instanceof Closure
            ? ($this->toggleTypeExists)($this->togglePasswordType)
            : class_exists($this->togglePasswordType);

        return $exists
            ? $this->togglePasswordType
            : SymfonyPasswordType::class;
    }
}
