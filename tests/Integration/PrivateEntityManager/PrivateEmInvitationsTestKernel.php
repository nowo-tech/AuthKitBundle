<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationsTestKernel;

/**
 * {@see InvitationsTestKernel} with `doctrine.private_entity_manager: true` (1.46.0).
 */
final class PrivateEmInvitationsTestKernel extends InvitationsTestKernel
{
    use PrivateEntityManagerKernel;
}
