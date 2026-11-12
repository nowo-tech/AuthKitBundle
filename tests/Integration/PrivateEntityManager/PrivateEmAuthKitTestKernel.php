<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;

/**
 * {@see AuthKitTestKernel} with `doctrine.private_entity_manager: true` (1.46.0).
 */
final class PrivateEmAuthKitTestKernel extends AuthKitTestKernel
{
    use PrivateEntityManagerKernel;
}
