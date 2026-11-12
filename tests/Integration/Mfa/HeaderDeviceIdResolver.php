<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdProviderInterface;
use Nowo\UserKitBundle\Sessions\Model\DeviceContext;

/**
 * Test device id: the `X-Device` request header (UserKit >= 1.15 framework-free provider).
 */
final class HeaderDeviceIdResolver implements DeviceIdProviderInterface
{
    public function deviceIdFor(DeviceContext $context): ?string
    {
        $id = $context->header('X-Device');

        return $id !== null && $id !== '' ? $id : null;
    }
}
