<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;

/**
 * {@see MfaTestKernel} with `doctrine.private_entity_manager: true` (1.46.0).
 */
final class PrivateEmMfaTestKernel extends MfaTestKernel
{
    use PrivateEntityManagerKernel;
}
