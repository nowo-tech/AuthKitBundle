<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

/**
 * Delivers a passwordless magic login link (email, SMS deep-link, etc.).
 */
interface MagicLoginNotifierInterface
{
    public function notify(MagicLoginNotificationContext $context): void;
}
