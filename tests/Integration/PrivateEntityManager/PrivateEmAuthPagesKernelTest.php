<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthPagesKernelTest;
use Nowo\AuthKitBundle\Tests\Support\HostModeNotices;

/**
 * Every test of {@see AuthPagesKernelTest} again with `doctrine.private_entity_manager: true` (1.46.0): the AuthKit rows go
 * through the private entity manager and the flows behave the same.
 */
final class PrivateEmAuthPagesKernelTest extends AuthPagesKernelTest
{
    use HostModeNotices;

    protected static function getKernelClass(): string
    {
        return PrivateEmAuthKitTestKernel::class;
    }
}
