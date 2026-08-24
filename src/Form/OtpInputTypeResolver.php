<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Closure;
use Symfony\Component\Form\FormTypeInterface;

use function class_exists;

/**
 * Resolves optional OtpType when nowo-tech/otp-input-bundle is installed.
 *
 * @phpstan-type OtpInputConfig array{enabled: bool, password_reset_code: bool}
 */
final class OtpInputTypeResolver
{
    public const OTP_TYPE = 'Nowo\\OtpInputBundle\\Form\\OtpType';

    /**
     * @param Closure(class-string<FormTypeInterface>): bool|null $typeExists Override for tests; null uses class_exists()
     */
    public function __construct(
        private readonly ?Closure $typeExists = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->classExists(self::OTP_TYPE);
    }

    /**
     * @return class-string<FormTypeInterface>|null
     */
    public function resolveType(): ?string
    {
        return $this->isAvailable() ? self::OTP_TYPE : null;
    }

    /**
     * @param OtpInputConfig $config
     */
    public function shouldUseForPasswordReset(array $config): bool
    {
        return $config['enabled'] && $config['password_reset_code'] && $this->isAvailable();
    }

    /**
     * @param OtpInputConfig $config
     *
     * @return class-string<FormTypeInterface>|null
     */
    public function resolvePasswordResetCodeType(array $config): ?string
    {
        return $this->shouldUseForPasswordReset($config) ? $this->resolveType() : null;
    }

    /**
     * @return array{length: int, numeric_only: bool, uppercase: bool}
     */
    public function fieldOptions(int $codeLength, string $charset): array
    {
        $numeric = $charset === 'numeric';

        return [
            'length'       => $codeLength,
            'numeric_only' => $numeric,
            'uppercase'    => !$numeric,
        ];
    }

    /**
     * @param class-string<FormTypeInterface> $class
     */
    private function classExists(string $class): bool
    {
        if ($this->typeExists instanceof Closure) {
            return ($this->typeExists)($class);
        }

        return class_exists($class);
    }
}
