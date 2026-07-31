<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\QrLogin\QrLoginUserResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class QrLoginUserResolverTest extends TestCase
{
    private QrLoginUserResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new QrLoginUserResolver(
            ProfileRegistryFactory::single(TestUser::class, [
                'qr_login' => [
                    'mode'                 => 'enabled',
                    'phone_field'          => 'phone',
                    'phone_verified_field' => 'phoneVerifiedAt',
                ],
            ]),
            PropertyAccess::createPropertyAccessor(),
        );
    }

    public function testValidPhoneReturnsValidAndHint(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');
        $user->setPhone('+34600111222');
        $user->setPhoneVerifiedAt(new DateTimeImmutable());

        $result = $this->resolver->validatePhone($user);

        self::assertTrue($result['valid']);
        self::assertNotNull($result['phone_hint']);
        self::assertStringContainsString('***', $result['phone_hint']);
        self::assertStringContainsString('22', $result['phone_hint']);
    }

    public function testEmptyPhoneReturnsInvalid(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');
        $user->setPhone('');
        $user->setPhoneVerifiedAt(new DateTimeImmutable());

        $result = $this->resolver->validatePhone($user);

        self::assertFalse($result['valid']);
        self::assertNull($result['phone_hint']);
    }

    public function testNullPhoneReturnsInvalid(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');

        $result = $this->resolver->validatePhone($user);

        self::assertFalse($result['valid']);
        self::assertNull($result['phone_hint']);
    }

    public function testUnverifiedPhoneReturnsInvalid(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');
        $user->setPhone('+34600111222');

        $result = $this->resolver->validatePhone($user);

        self::assertFalse($result['valid']);
        self::assertNull($result['phone_hint']);
    }

    public function testShortPhoneMask(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');
        $user->setPhone('1234');
        $user->setPhoneVerifiedAt(new DateTimeImmutable());

        $result = $this->resolver->validatePhone($user);

        self::assertTrue($result['valid']);
        self::assertSame('***34', $result['phone_hint']);
    }

    public function testValidatePhoneWithNamedProfile(): void
    {
        $user = new TestUser();
        $user->setEmail('test@example.com');
        $user->setPhone('+34600111222');
        $user->setPhoneVerifiedAt(new DateTimeImmutable());

        $result = $this->resolver->validatePhone($user, 'default');

        self::assertTrue($result['valid']);
    }
}
