<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\MultiAccountTestKernel;

/**
 * {@see MultiAccountTestKernel} with `doctrine.private_entity_manager: true` (1.46.0).
 */
final class PrivateEmMultiAccountTestKernel extends MultiAccountTestKernel
{
    use PrivateEntityManagerKernel;
}
