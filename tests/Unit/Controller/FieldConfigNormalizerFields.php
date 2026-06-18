<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;

/**
 * Shared normalized field fixtures for controller/form tests.
 */
final class FieldConfigNormalizerFields
{
    /**
     * @return list<array{name: string, type: string, property: ?string, hash: bool, required: bool, security_name: ?string}>
     */
    public static function login(): array
    {
        return FieldConfigNormalizer::normalizeLoginFields(['identifier', 'password'], 'email');
    }

    /**
     * @return list<array{name: string, type: string, property: string, hash: bool, required: bool, security_name: null}>
     */
    public static function registration(): array
    {
        return FieldConfigNormalizer::normalizeRegistrationFields(['email', 'password']);
    }
}
