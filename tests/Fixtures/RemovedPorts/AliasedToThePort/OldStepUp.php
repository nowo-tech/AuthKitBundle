<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\AliasedToThePort;

use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 2.0 W1-1 fixture (registered explicitly, aliased to the port's id): a class that still implements only the removed
 * QR step-up interface.
 */
final class OldStepUp implements QrLoginStepUpInterface
{
    public function assertUnlocked(Request $request): void
    {
    }
}
