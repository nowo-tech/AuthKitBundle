<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Config;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class FieldConfigNormalizerTest extends TestCase
{
    public function testNormalizeLoginFields(): void
    {
        $fields = FieldConfigNormalizer::normalizeLoginFields(
            ['identifier', 'password', 'remember_me'],
            'email',
        );

        self::assertSame('_username', $fields[0]['name']);
        self::assertSame('email', $fields[0]['property']);
        self::assertSame('_password', $fields[1]['name']);
        self::assertSame('_remember_me', $fields[2]['name']);
    }

    public function testNormalizeLoginFieldsWithEmailIdentifierType(): void
    {
        $fields = FieldConfigNormalizer::normalizeLoginFields(
            ['identifier' => ['type' => 'email']],
            'email',
        );

        self::assertSame('email', $fields[0]['type']);
    }

    public function testNormalizeLoginFieldsWithNumericKey(): void
    {
        $fields = FieldConfigNormalizer::normalizeLoginFields(
            [['name' => 'identifier']],
            'email',
        );

        self::assertSame('_username', $fields[0]['name']);
    }

    public function testNormalizeRegistrationFields(): void
    {
        $fields = FieldConfigNormalizer::normalizeRegistrationFields([
            'email',
            'password' => ['property' => 'password', 'hash' => true],
            'name'     => ['type' => 'text', 'property' => 'fullName'],
        ]);

        self::assertSame('email', $fields[0]['property']);
        self::assertTrue($fields[1]['hash']);
        self::assertSame('fullName', $fields[2]['property']);
    }

    public function testNormalizeRegistrationFieldsDefaultTypes(): void
    {
        $fields = FieldConfigNormalizer::normalizeRegistrationFields([
            'plainPassword',
            'plain_password',
            'username',
        ]);

        self::assertSame('password', $fields[0]['type']);
        self::assertSame('password', $fields[1]['type']);
        self::assertSame('text', $fields[2]['type']);
    }

    public function testNormalizeRegistrationFieldsWithNumericKey(): void
    {
        $fields = FieldConfigNormalizer::normalizeRegistrationFields([
            ['name' => 'email'],
        ]);

        self::assertSame('email', $fields[0]['name']);
    }

    public function testUnsupportedLoginFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FieldConfigNormalizer::normalizeLoginFields(['unknown'], 'email');
    }

    public function testInvalidLoginFieldTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Login fields must be strings or arrays.');
        /* @phpstan-ignore argument.type (intentionally invalid fixture) */
        FieldConfigNormalizer::normalizeLoginFields([123], 'email');
    }

    public function testLoginFieldArrayWithoutNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Login field array must have a name.');
        FieldConfigNormalizer::normalizeLoginFields([[]], 'email');
    }

    public function testUnsupportedRegistrationTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FieldConfigNormalizer::normalizeRegistrationFields([
            'bad' => ['type' => 'unsupported'],
        ]);
    }

    public function testInvalidRegistrationFieldTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Registration fields must be strings or arrays.');
        /* @phpstan-ignore argument.type (intentionally invalid fixture) */
        FieldConfigNormalizer::normalizeRegistrationFields([false]);
    }

    public function testRegistrationFieldArrayWithoutNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Registration field array must have a name.');
        FieldConfigNormalizer::normalizeRegistrationFields([[]]);
    }
}
