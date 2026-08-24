<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\QrLogin\DeviceIntelligenceQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DeviceIntelligenceQrLoginStepUpTest extends TestCase
{
    public function testDelegatesWhenFlagOff(): void
    {
        $inner = $this->createMock(QrLoginStepUpInterface::class);
        $inner->expects(self::once())->method('assertUnlocked');

        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $stepUp->assertUnlocked(Request::create('/approve'));
    }

    public function testRequiresObservationWhenTrustedFlagOn(): void
    {
        $stepUp = $this->stepUp(trusted: true);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Device observation required');

        $stepUp->assertUnlocked(Request::create('/approve'));
    }

    public function testRequiresTrustedDevice(): void
    {
        $stepUp  = $this->stepUp(trusted: true);
        $request = Request::create('/approve');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isTrusted(): bool
            {
                return false;
            }
        });

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('trusted device');

        $stepUp->assertUnlocked($request);
    }

    public function testAllowsTrustedDeviceWhenInnerIsNull(): void
    {
        $stepUp  = $this->stepUp(trusted: true);
        $request = Request::create('/approve');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isTrusted(): bool
            {
                return true;
            }
        });

        $stepUp->assertUnlocked($request);
        $this->addToAssertionCount(1);
    }

    public function testCallsCustomInnerAfterTrustedCheck(): void
    {
        $inner = $this->createMock(QrLoginStepUpInterface::class);
        $inner->expects(self::once())->method('assertUnlocked');

        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => false,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => true],
                ],
            ]),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $request = Request::create('/approve');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isTrusted(): bool
            {
                return true;
            }
        });

        $stepUp->assertUnlocked($request);
    }

    public function testDoesNotCallCustomInnerWhenUntrusted(): void
    {
        $inner = $this->createMock(QrLoginStepUpInterface::class);
        $inner->expects(self::never())->method('assertUnlocked');

        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => false,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => true],
                ],
            ]),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $request = Request::create('/approve');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isTrusted(): bool
            {
                return false;
            }
        });

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('trusted device');

        $stepUp->assertUnlocked($request);
    }

    public function testDelegatesToNullWhenBundleMissing(): void
    {
        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            new NullQrLoginStepUp(),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => false,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => true],
                ],
            ]),
            new DeviceIntelligenceContext(static fn (string $class): bool => false),
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('QR login step-up not implemented');

        $stepUp->assertUnlocked(Request::create('/approve'));
    }

    private function stepUp(bool $trusted): DeviceIntelligenceQrLoginStepUp
    {
        return new DeviceIntelligenceQrLoginStepUp(
            new NullQrLoginStepUp(),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => false,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => $trusted],
                ],
            ]),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );
    }
}
