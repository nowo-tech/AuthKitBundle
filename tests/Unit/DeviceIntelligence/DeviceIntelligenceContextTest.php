<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DeviceIntelligence;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class DeviceIntelligenceContextTest extends TestCase
{
    /**
     * @return array{enabled: bool, collect_on_auth_pages: bool, collect_endpoint: string, new_device_notify: bool, device_rate_limit: bool, qr_login: array{approve_require_trusted: bool}}
     */
    private function config(bool $enabled = true, bool $rate = false, bool $notify = false, bool $trusted = false, bool $assets = true): array
    {
        return [
            'enabled'               => $enabled,
            'collect_on_auth_pages' => $assets,
            'collect_endpoint'      => '/_device/collect',
            'new_device_notify'     => $notify,
            'device_rate_limit'     => $rate,
            'qr_login'              => ['approve_require_trusted' => $trusted],
        ];
    }

    public function testAvailabilityAndFlags(): void
    {
        $available = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $missing   = new DeviceIntelligenceContext(static fn (string $class): bool => false);

        self::assertTrue($available->isAvailable());
        self::assertFalse($missing->isAvailable());
        self::assertTrue($available->shouldLoadAssets($this->config()));
        self::assertFalse($available->shouldLoadAssets($this->config(enabled: false)));
        self::assertFalse($available->shouldLoadAssets($this->config(assets: false)));
        self::assertFalse($missing->shouldLoadAssets($this->config()));
        self::assertTrue($available->shouldNotifyNewDevice($this->config(notify: true)));
        self::assertFalse($available->shouldNotifyNewDevice($this->config(notify: false)));
        self::assertTrue($available->shouldRequireTrustedOnQrApprove($this->config(trusted: true)));
        self::assertFalse($missing->shouldRequireTrustedOnQrApprove($this->config(trusted: true)));
        self::assertTrue($available->shouldRateLimitByDevice($this->config(rate: true)));
        self::assertFalse($available->shouldRateLimitByDevice($this->config(rate: false)));
    }

    public function testReadsTrustedNewAndDeviceIdFromRequest(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public object $id;

            public function __construct()
            {
                $this->id = new class {
                    public string $value = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
                };
            }

            public function isTrusted(): bool
            {
                return true;
            }

            public function isNew(): bool
            {
                return true;
            }

            public function device(): object
            {
                return $this;
            }
        };

        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertTrue($context->isTrusted($request));
        self::assertTrue($context->isNew($request));
        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $context->deviceId($request));
        self::assertNull($context->fromRequest(null));
        self::assertFalse($context->isTrusted(null));
        self::assertFalse($context->isNew(null));
        self::assertNull($context->deviceId(null));
    }

    public function testDeviceIdAcceptsStringableScalarId(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): object
            {
                return new class {
                    public int $id = 42;
                };
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertSame('42', $context->deviceId($request));
    }

    public function testDeviceIdReturnsNullForUnknownShapes(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function device(): string
            {
                return 'nope';
            }
        });

        self::assertNull($context->deviceId($request));
        self::assertFalse($context->isTrusted($request));
        self::assertFalse($context->isNew($request));
    }

    public function testIgnoresAttributeWhenBundleMissing(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => false);
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new stdClass());

        self::assertNull($context->fromRequest($request));
    }

    public function testDeviceIdStringProperty(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): object
            {
                return new class {
                    public string $id = 'dev-1';
                };
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertSame('dev-1', $context->deviceId($request));
    }

    public function testDeviceIdObjectWithoutValue(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): object
            {
                return new class {
                    public object $id;

                    public function __construct()
                    {
                        $this->id = new stdClass();
                    }
                };
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertNull($context->deviceId($request));
    }

    public function testDeviceIdWhenEntityHasNoId(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): stdClass
            {
                return new stdClass();
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertNull($context->deviceId($request));
    }

    public function testDeviceIdRejectsNonScalarShapes(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): object
            {
                return new class {
                    /** @var list<string> */
                    public array $id = ['nope'];
                };
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertNull($context->deviceId($request));
    }

    public function testDeviceIdObjectWithNonScalarValue(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $device  = new class {
            public function device(): object
            {
                return new class {
                    public object $id;

                    public function __construct()
                    {
                        $this->id = new class {
                            /** @var list<string> */
                            public array $value = ['x'];
                        };
                    }
                };
            }
        };
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertNull($context->deviceId($request));
    }

    public function testNonObjectAttributeIsIgnored(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, 'nope');

        self::assertNull($context->fromRequest($request));
    }

    public function testDeviceIdWithoutDeviceMethod(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $request = Request::create('/');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new stdClass());

        self::assertNull($context->deviceId($request));
    }

    public function testConsumeDeviceRateLimit(): void
    {
        $context = new DeviceIntelligenceContext(static fn (string $class): bool => true);
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());
        $off     = $this->config(rate: false);
        $on      = $this->config(rate: true);

        self::assertTrue($context->consumeDeviceRateLimit(null, $limiter, $off, 'register', 'default', 1, 60));

        $request = Request::create('/');
        self::assertTrue($context->consumeDeviceRateLimit($request, $limiter, $on, 'register', 'default', 1, 60));

        $device = new class {
            public function device(): object
            {
                return new class {
                    public object $id;

                    public function __construct()
                    {
                        $this->id = new class {
                            public string $value = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
                        };
                    }
                };
            }
        };
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, $device);

        self::assertTrue($context->consumeDeviceRateLimit($request, $limiter, $on, 'register', 'default', 1, 60));
        self::assertFalse($context->consumeDeviceRateLimit($request, $limiter, $on, 'register', 'default', 1, 60));
    }

    public function testClassExistsFallback(): void
    {
        $native = new DeviceIntelligenceContext();

        self::assertSame(
            class_exists(DeviceIntelligenceContext::CONTEXT_CLASS),
            $native->isAvailable(),
        );
    }
}
