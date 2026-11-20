<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationServicesKernelTest;
use Nowo\AuthKitBundle\Tests\Support\HostModeNotices;

/**
 * Every test of {@see InvitationServicesKernelTest} again with `doctrine.private_entity_manager: true` (1.46.0): the AuthKit rows go
 * through the private entity manager and the flows behave the same.
 */
final class PrivateEmInvitationServicesKernelTest extends InvitationServicesKernelTest
{
    use HostModeNotices;

    protected static function getKernelClass(): string
    {
        return PrivateEmInvitationsTestKernel::class;
    }
}
