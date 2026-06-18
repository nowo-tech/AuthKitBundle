<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Config;

use InvalidArgumentException;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Normalizes login and registration field definitions from YAML.
 */
final class FieldConfigNormalizer
{
    private const SUPPORTED_TYPES = ['text', 'email', 'password', 'checkbox'];

    /**
     * @param array<int|string, array<string, mixed>|string> $fields
     *
     * @return list<array{name: string, type: string, property: ?string, hash: bool, required: bool, security_name: ?string}>
     */
    public static function normalizeLoginFields(array $fields, string $userIdentifierField): array
    {
        $normalized = [];

        foreach ($fields as $key => $field) {
            if (is_string($field)) {
                $name   = $field;
                $config = ['name' => $name];
            } elseif (is_array($field)) {
                $name   = is_int($key) ? (string) ($field['name'] ?? throw new InvalidArgumentException('Login field array must have a name.')) : $key;
                $config = $field + ['name' => $name];
            } else {
                throw new InvalidArgumentException('Login fields must be strings or arrays.');
            }

            $name = (string) $config['name'];

            if ($name === 'identifier') {
                $normalized[] = [
                    'name'          => '_username',
                    'type'          => (string) ($config['type'] ?? 'text'),
                    'property'      => $userIdentifierField,
                    'hash'          => false,
                    'required'      => (bool) ($config['required'] ?? true),
                    'security_name' => '_username',
                ];
                continue;
            }

            if ($name === 'password') {
                $normalized[] = [
                    'name'          => '_password',
                    'type'          => 'password',
                    'property'      => null,
                    'hash'          => false,
                    'required'      => (bool) ($config['required'] ?? true),
                    'security_name' => '_password',
                ];
                continue;
            }

            if ($name === 'remember_me') {
                $normalized[] = [
                    'name'          => '_remember_me',
                    'type'          => 'checkbox',
                    'property'      => null,
                    'hash'          => false,
                    'required'      => false,
                    'security_name' => '_remember_me',
                ];
                continue;
            }

            throw new InvalidArgumentException(sprintf('Unsupported login field "%s". Use identifier, password, or remember_me.', $name));
        }

        return $normalized;
    }

    /**
     * @param array<int|string, array<string, mixed>|string> $fields
     *
     * @return list<array{name: string, type: string, property: string, hash: bool, required: bool, security_name: null}>
     */
    public static function normalizeRegistrationFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $key => $field) {
            if (is_string($field)) {
                $name   = $field;
                $config = ['name' => $name];
            } elseif (is_array($field)) {
                $name   = is_int($key) ? (string) ($field['name'] ?? throw new InvalidArgumentException('Registration field array must have a name.')) : $key;
                $config = $field + ['name' => $name];
            } else {
                throw new InvalidArgumentException('Registration fields must be strings or arrays.');
            }

            $name     = (string) $config['name'];
            $type     = (string) ($config['type'] ?? self::defaultTypeForName($name));
            $property = (string) ($config['property'] ?? $name);

            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported registration field type "%s".', $type));
            }

            $hash = (bool) ($config['hash'] ?? $type === 'password');

            $normalized[] = [
                'name'          => $name,
                'type'          => $type,
                'property'      => $property,
                'hash'          => $hash,
                'required'      => (bool) ($config['required'] ?? true),
                'security_name' => null,
            ];
        }

        return $normalized;
    }

    private static function defaultTypeForName(string $name): string
    {
        return match ($name) {
            'email'                                       => 'email',
            'password', 'plainPassword', 'plain_password' => 'password',
            default                                       => 'text',
        };
    }
}
