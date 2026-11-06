<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;

final class ToggleMailReadyChecker implements OutboundMailReadyCheckerInterface
{
    public bool $ready = true;

    public function isReady(): bool
    {
        return $this->ready;
    }
}
