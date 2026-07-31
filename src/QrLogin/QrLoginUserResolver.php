<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Resolves and validates users for QR login approval by phone field.
 */
final class QrLoginUserResolver
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * Checks that the user has a verified phone and returns a masked hint.
     *
     * @return array{valid: bool, phone_hint: string|null}
     */
    public function validatePhone(UserInterface $user, ?string $profileName = null): array
    {
        $profile       = $this->resolveProfile($profileName);
        $phoneField    = $profile->qrLogin['phone_field'] ?? 'phone';
        $verifiedField = $profile->qrLogin['phone_verified_field'] ?? 'phoneVerifiedAt';

        $phone = $this->propertyAccessor->getValue($user, $phoneField);
        if (!is_string($phone) || $phone === '') {
            return ['valid' => false, 'phone_hint' => null];
        }

        $verified = $this->propertyAccessor->getValue($user, $verifiedField);
        if ($verified === null) {
            return ['valid' => false, 'phone_hint' => null];
        }

        return ['valid' => true, 'phone_hint' => $this->maskPhone($phone)];
    }

    private function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 4) {
            return '***' . mb_substr($phone, -2);
        }

        return mb_substr($phone, 0, 3) . ' *** ' . mb_substr($phone, -2);
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
