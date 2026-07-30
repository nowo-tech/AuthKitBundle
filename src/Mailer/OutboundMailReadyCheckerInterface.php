<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mailer;

interface OutboundMailReadyCheckerInterface
{
    public function isReady(): bool;
}
