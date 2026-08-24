<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DeviceIntelligence;

use Closure;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Symfony\Component\HttpFoundation\Request;

use function class_exists;
use function is_int;
use function is_object;
use function is_scalar;
use function is_string;
use function method_exists;

/**
 * Optional DeviceIntelligenceBundle bridge. Never imports that package so AuthKit stays PHP 8.2.
 *
 * @phpstan-type DeviceIntelligenceConfig array{
 *     enabled: bool,
 *     collect_on_auth_pages: bool,
 *     collect_endpoint: string,
 *     new_device_notify: bool,
 *     device_rate_limit: bool,
 *     qr_login: array{approve_require_trusted: bool}
 * }
 */
final class DeviceIntelligenceContext
{
    public const REQUEST_ATTRIBUTE = '_device';

    public const CONTEXT_CLASS = 'Nowo\\DeviceIntelligenceBundle\\Request\\DeviceContext';

    public const SESSION_NEW_DEVICE = 'nowo_auth_kit.new_device';

    /**
     * @param Closure(string): bool|null $classExists Override for tests; null uses class_exists()
     */
    public function __construct(
        private readonly ?Closure $classExists = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->classExists(self::CONTEXT_CLASS);
    }

    /**
     * @param DeviceIntelligenceConfig $config
     */
    public function shouldLoadAssets(array $config): bool
    {
        return $config['enabled']
            && $config['collect_on_auth_pages']
            && $this->isAvailable();
    }

    /**
     * @param DeviceIntelligenceConfig $config
     */
    public function shouldNotifyNewDevice(array $config): bool
    {
        return $config['enabled'] && $config['new_device_notify'] && $this->isAvailable();
    }

    /**
     * @param DeviceIntelligenceConfig $config
     */
    public function shouldRequireTrustedOnQrApprove(array $config): bool
    {
        return $config['enabled']
            && $config['qr_login']['approve_require_trusted']
            && $this->isAvailable();
    }

    /**
     * @param DeviceIntelligenceConfig $config
     */
    public function shouldRateLimitByDevice(array $config): bool
    {
        return $config['enabled'] && $config['device_rate_limit'] && $this->isAvailable();
    }

    public function fromRequest(?Request $request): ?object
    {
        if (!$request instanceof Request || !$this->isAvailable()) {
            return null;
        }

        $device = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_object($device) ? $device : null;
    }

    public function isTrusted(?Request $request): bool
    {
        $device = $this->fromRequest($request);

        return $device !== null && method_exists($device, 'isTrusted') && $device->isTrusted();
    }

    public function isNew(?Request $request): bool
    {
        $device = $this->fromRequest($request);

        return $device !== null && method_exists($device, 'isNew') && $device->isNew();
    }

    public function deviceId(?Request $request): ?string
    {
        $device = $this->fromRequest($request);
        if ($device === null || !method_exists($device, 'device')) {
            return null;
        }

        $entity = $device->device();
        if (!is_object($entity) || !isset($entity->id)) {
            return null;
        }

        $id = $entity->id;
        if (is_object($id) && isset($id->value) && is_scalar($id->value)) {
            return (string) $id->value;
        }

        if (is_string($id) || is_int($id)) {
            return (string) $id;
        }

        return null;
    }

    /**
     * Extra consume by device ULID. Missing observation is a no-op so the first HTML hit is not locked out.
     *
     * @param DeviceIntelligenceConfig $config
     */
    public function consumeDeviceRateLimit(
        ?Request $request,
        AuthKitAttemptLimiter $limiter,
        array $config,
        string $prefix,
        string $profileName,
        int $maxAttempts,
        int $windowSeconds,
    ): bool {
        if (!$this->shouldRateLimitByDevice($config)) {
            return true;
        }

        $deviceId = $this->deviceId($request);
        if ($deviceId === null) {
            return true;
        }

        return $limiter->consume($prefix . ':device:' . $profileName . ':' . $deviceId, $maxAttempts, $windowSeconds);
    }

    private function classExists(string $class): bool
    {
        if ($this->classExists instanceof Closure) {
            return ($this->classExists)($class);
        }

        return class_exists($class);
    }
}
