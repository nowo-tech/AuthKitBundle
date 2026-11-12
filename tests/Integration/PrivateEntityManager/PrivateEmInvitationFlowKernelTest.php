<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationFlowKernelTest;

/**
 * Every test of {@see InvitationFlowKernelTest} again with `doctrine.private_entity_manager: true` (1.46.0): the AuthKit rows go
 * through the private entity manager and the flows behave the same.
 */
final class PrivateEmInvitationFlowKernelTest extends InvitationFlowKernelTest
{
    protected static function getKernelClass(): string
    {
        return PrivateEmInvitationsTestKernel::class;
    }
}
