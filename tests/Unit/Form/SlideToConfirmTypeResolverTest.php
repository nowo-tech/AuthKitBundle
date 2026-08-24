<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use PHPUnit\Framework\TestCase;

final class SlideToConfirmTypeResolverTest extends TestCase
{
    public function testIsAvailableFollowsClassExists(): void
    {
        $available = new SlideToConfirmTypeResolver(static fn (string $class): bool => true);
        $missing   = new SlideToConfirmTypeResolver(static fn (string $class): bool => false);

        self::assertTrue($available->isAvailable());
        self::assertFalse($missing->isAvailable());
        self::assertNotNull($available->resolveSlideType());
        self::assertNull($missing->resolveSlideType());
        self::assertNotNull($available->resolveSwipeType());
        self::assertNull($missing->resolveSwipeType());
    }

    public function testResolveSwipeTypeFallsBackToSlideType(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static function (string $class): bool {
            return $class === 'Nowo\\SlideToConfirmBundle\\Form\\Type\\SlideToConfirmType';
        });

        self::assertSame(
            'Nowo\\SlideToConfirmBundle\\Form\\Type\\SlideToConfirmType',
            $resolver->resolveSwipeType(),
        );
    }

    public function testResolveRegistrationProfileRequiresEnabledAndAvailability(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static fn (string $class): bool => true);
        $field    = ['slide_to_confirm' => true];

        self::assertNull($resolver->resolveRegistrationProfile($field, [
            'enabled'              => false,
            'registration_consent' => 'gate',
            'qr_login_approve'     => false,
        ]));
        self::assertSame('gate', $resolver->resolveRegistrationProfile($field, [
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => false,
        ]));
    }

    public function testResolveRegistrationProfileUsesExplicitString(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static fn (string $class): bool => true);

        self::assertSame('legal', $resolver->resolveRegistrationProfile(
            ['slide_to_confirm' => 'legal'],
            [
                'enabled'              => true,
                'registration_consent' => 'gate',
                'qr_login_approve'     => false,
            ],
        ));
        self::assertNull($resolver->resolveRegistrationProfile(
            ['slide_to_confirm' => false],
            [
                'enabled'              => true,
                'registration_consent' => 'gate',
                'qr_login_approve'     => false,
            ],
        ));
        self::assertNull($resolver->resolveRegistrationProfile(
            ['slide_to_confirm' => true],
            [
                'enabled'              => true,
                'registration_consent' => false,
                'qr_login_approve'     => false,
            ],
        ));
    }

    public function testResolveRegistrationSlideModeReturnsFirstMatch(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static fn (string $class): bool => true);

        self::assertSame('gate', $resolver->resolveRegistrationSlideMode([
            ['name' => 'email', 'slide_to_confirm' => false],
            ['name' => 'terms', 'slide_to_confirm' => true],
        ], [
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => false,
        ]));
        self::assertNull($resolver->resolveRegistrationSlideMode([
            ['name' => 'email'],
        ], [
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => false,
        ]));
    }

    public function testResolveQrApproveProfile(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static fn (string $class): bool => true);

        self::assertSame('danger', $resolver->resolveQrApproveProfile([
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => 'danger',
        ]));
        self::assertNull($resolver->resolveQrApproveProfile([
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => false,
        ]));
        self::assertNull($resolver->resolveQrApproveProfile([
            'enabled'              => false,
            'registration_consent' => 'gate',
            'qr_login_approve'     => 'danger',
        ]));
    }

    public function testUnavailableBundleIgnoresConfig(): void
    {
        $resolver = new SlideToConfirmTypeResolver(static fn (string $class): bool => false);

        self::assertNull($resolver->resolveRegistrationProfile(
            ['slide_to_confirm' => true],
            [
                'enabled'              => true,
                'registration_consent' => 'gate',
                'qr_login_approve'     => 'danger',
            ],
        ));
        self::assertNull($resolver->resolveQrApproveProfile([
            'enabled'              => true,
            'registration_consent' => 'gate',
            'qr_login_approve'     => 'danger',
        ]));
    }
}
