<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

/**
 * Dispatched when a magic login link is generated for an existing user.
 */
final class MagicLoginRequestedEvent
{
    public function __construct(
        private readonly MagicLoginNotificationContext $context,
    ) {
    }

    public function getContext(): MagicLoginNotificationContext
    {
        return $this->context;
    }
}
