<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Closure;
use Symfony\Component\Form\FormTypeInterface;

use function class_exists;
use function is_string;

/**
 * Resolves optional SlideToConfirm form types when nowo-tech/slide-to-confirm-bundle is installed.
 *
 * @phpstan-type SlideToConfirmConfig array{
 *     enabled: bool,
 *     registration_consent: string|false,
 *     qr_login_approve: string|false
 * }
 */
final class SlideToConfirmTypeResolver
{
    private const SLIDE_TYPE = 'Nowo\\SlideToConfirmBundle\\Form\\Type\\SlideToConfirmType';

    private const SWIPE_TYPE = 'Nowo\\SlideToConfirmBundle\\Form\\Type\\SwipeToSubmitType';

    /**
     * @param Closure(class-string<FormTypeInterface>): bool|null $typeExists Override for tests; null uses class_exists()
     */
    public function __construct(
        private readonly ?Closure $typeExists = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->classExists(self::SLIDE_TYPE);
    }

    /**
     * @return class-string<FormTypeInterface>|null
     */
    public function resolveSlideType(): ?string
    {
        return $this->isAvailable() ? self::SLIDE_TYPE : null;
    }

    /**
     * @return class-string<FormTypeInterface>|null
     */
    public function resolveSwipeType(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return $this->classExists(self::SWIPE_TYPE) ? self::SWIPE_TYPE : self::SLIDE_TYPE;
    }

    /**
     * @param array<string, mixed> $field
     * @param SlideToConfirmConfig $config
     */
    public function resolveRegistrationProfile(array $field, array $config): ?string
    {
        if (!$config['enabled'] || !$this->isAvailable()) {
            return null;
        }

        $flag = $field['slide_to_confirm'] ?? false;
        if ($flag === false || $flag === '') {
            return null;
        }

        if ($flag === true) {
            $consent = $config['registration_consent'];

            return is_string($consent) && $consent !== '' ? $consent : null;
        }

        return is_string($flag) ? $flag : null;
    }

    /**
     * @param list<array<string, mixed>> $registrationFields
     * @param SlideToConfirmConfig $config
     */
    public function resolveRegistrationSlideMode(array $registrationFields, array $config): ?string
    {
        foreach ($registrationFields as $field) {
            $profile = $this->resolveRegistrationProfile($field, $config);
            if ($profile !== null) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @param SlideToConfirmConfig $config
     */
    public function resolveQrApproveProfile(array $config): ?string
    {
        if (!$config['enabled'] || !$this->isAvailable()) {
            return null;
        }

        $profile = $config['qr_login_approve'];

        return is_string($profile) && $profile !== '' ? $profile : null;
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
