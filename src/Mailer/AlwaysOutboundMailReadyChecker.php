<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mailer;

final class AlwaysOutboundMailReadyChecker implements OutboundMailReadyCheckerInterface
{
    public function isReady(): bool
    {
        return true;
    }
}
