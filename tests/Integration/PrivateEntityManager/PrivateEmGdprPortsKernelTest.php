<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\PersonalData\GdprPortsKernelTest;

/**
 * Every test of {@see GdprPortsKernelTest} again with `doctrine.private_entity_manager: true` (1.46.0): the AuthKit rows go
 * through the private entity manager and the flows behave the same.
 */
final class PrivateEmGdprPortsKernelTest extends GdprPortsKernelTest
{
    protected static function getKernelClass(): string
    {
        return PrivateEmGdprPortsTestKernel::class;
    }
}
