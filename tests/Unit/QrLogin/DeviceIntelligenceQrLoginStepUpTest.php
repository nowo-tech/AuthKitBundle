<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\Port\QrLoginStepUpGate;
use Nowo\AuthKitBundle\QrLogin\DeviceIntelligenceQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * 2.0: the Device Intelligence step-up decorates the QR step-up port (it decorated the removed QrLoginStepUpInterface)
 * and keeps its behaviour: the trusted-device check of the current request first, then the inner policy (skipped when
 * it is the always-refusing default). Through the gate, a refusal is the same 403.
 */
final class DeviceIntelligenceQrLoginStepUpTest extends TestCase
{
    private const DEVICE_INTELLIGENCE = [
        'enabled'               => true,
        'collect_on_auth_pages' => true,
        'collect_endpoint'      => '/_device/collect',
        'new_device_notify'     => false,
        'device_rate_limit'     => false,
        'qr_login'              => ['approve_require_trusted' => true],
    ];

    public function testDelegatesWhenFlagOff(): void
    {
        $inner = $this->createMock(QrLoginStepUpPolicyInterface::class);
        $inner->expects(self::once())->method('isUnlocked')->willReturn(true);

        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
            $this->stack(Request::create('/approve')),
        );

        self::assertTrue($stepUp->isUnlocked($this->context(), null));
    }

    public function testWithoutACurrentRequestItRefuses(): void
    {
        $inner = $this->createMock(QrLoginStepUpPolicyInterface::class);
        $inner->expects(self::never())->method('isUnlocked');

        $stepUp = new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
            new RequestStack(),
        );

        self::assertFalse($stepUp->isUnlocked($this->context(), null));
        self::assertFalse((new DeviceIntelligenceQrLoginStepUp($inner, ProfileRegistryFactory::requestResolver(TestUser::class)))->isUnlocked($this->context(), null));
    }

    public function testRequiresObservationWhenTrustedFlagOn(): void
    {
        self::assertFalse($this->stepUp(new NullQrLoginStepUp(), Request::create('/approve'))->isUnlocked($this->context(), null));
    }

    public function testRequiresTrustedDevice(): void
    {
        self::assertFalse($this->stepUp(new NullQrLoginStepUp(), $this->observed(false))->isUnlocked($this->context(), null));
    }

    public function testAllowsTrustedDeviceWhenInnerIsNull(): void
    {
        self::assertTrue($this->stepUp(new NullQrLoginStepUp(), $this->observed(true))->isUnlocked($this->context(), null));
    }

    public function testCallsCustomInnerAfterTrustedCheck(): void
    {
        $inner = $this->createMock(QrLoginStepUpPolicyInterface::class);
        $inner->expects(self::once())->method('isUnlocked')->willReturn(false);

        self::assertFalse($this->stepUp($inner, $this->observed(true))->isUnlocked($this->context(), null));
    }

    public function testDoesNotCallCustomInnerWhenUntrusted(): void
    {
        $inner = $this->createMock(QrLoginStepUpPolicyInterface::class);
        $inner->expects(self::never())->method('isUnlocked');

        self::assertFalse($this->stepUp($inner, $this->observed(false))->isUnlocked($this->context(), null));
    }

    public function testDelegatesToNullWhenBundleMissing(): void
    {
        $request = Request::create('/approve');
        $stepUp  = new DeviceIntelligenceQrLoginStepUp(
            new NullQrLoginStepUp(),
            ProfileRegistryFactory::requestResolver(TestUser::class, ['device_intelligence' => self::DEVICE_INTELLIGENCE]),
            new DeviceIntelligenceContext(static fn (string $class): bool => false),
            $this->stack($request),
        );
        $gate = new QrLoginStepUpGate($stepUp, ProfileRegistryFactory::requestResolver(TestUser::class));

        // Through the gate the refusal is the 403 of the approval.
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('QR login step-up refused.');
        $gate->assertUnlocked($request);
    }

    private function stepUp(QrLoginStepUpPolicyInterface $inner, Request $request): DeviceIntelligenceQrLoginStepUp
    {
        return new DeviceIntelligenceQrLoginStepUp(
            $inner,
            ProfileRegistryFactory::requestResolver(TestUser::class, ['device_intelligence' => self::DEVICE_INTELLIGENCE]),
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
            $this->stack($request),
        );
    }

    private function observed(bool $trusted): Request
    {
        $request = Request::create('/approve');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $trusted ? new class {
            public function isTrusted(): bool
            {
                return true;
            }
        } : new class {
            public function isTrusted(): bool
            {
                return false;
            }
        });

        return $request;
    }

    private function stack(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function context(): AuthRequestContext
    {
        return AuthRequestContext::fromValues('POST', '/approve');
    }
}
