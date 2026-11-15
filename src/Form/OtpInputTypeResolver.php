<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Closure;
use Symfony\Component\Form\FormTypeInterface;

use function class_exists;

/**
 * Resolves optional OtpType when nowo-tech/otp-input-bundle is installed: the password-reset code and (1.51) the
 * authenticator-app code of the MFA forms.
 *
 * @phpstan-type OtpInputConfig array{enabled: bool, password_reset_code: bool, mfa_code?: bool, mfa_code_autosubmit?: bool}
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
     * 1.51: whether the MFA code field asks for OtpType (`otp_input.enabled` and `otp_input.mfa_code`, default true).
     * The form type still falls back to a text field when the package is missing ({@see self::resolveMfaCodeType()}).
     *
     * @param OtpInputConfig $config
     */
    public function wantsMfaCode(array $config): bool
    {
        return $config['enabled'] && ($config['mfa_code'] ?? true);
    }

    /**
     * 1.51: autosubmit of the MFA code form once every box is filled (`otp_input.mfa_code_autosubmit`, default false).
     *
     * @param OtpInputConfig $config
     */
    public function wantsMfaCodeAutosubmit(array $config): bool
    {
        return $this->wantsMfaCode($config) && ($config['mfa_code_autosubmit'] ?? false);
    }

    /**
     * 1.51: OtpType for the MFA code when the form asks for it and the package is installed, else null (text field).
     *
     * @return class-string<FormTypeInterface>|null
     */
    public function resolveMfaCodeType(bool $wanted): ?string
    {
        return $wanted ? $this->resolveType() : null;
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
