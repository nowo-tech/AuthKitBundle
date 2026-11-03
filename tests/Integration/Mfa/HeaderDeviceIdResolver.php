<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\UserKitBundle\Sessions\Client\DeviceIdResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test device id: the `X-Device` request header.
 */
final class HeaderDeviceIdResolver implements DeviceIdResolverInterface
{
    public function deviceId(Request $request): ?string
    {
        $id = $request->headers->get('X-Device');

        return $id !== null && $id !== '' ? $id : null;
    }
}
