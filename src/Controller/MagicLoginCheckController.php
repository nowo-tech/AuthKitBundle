<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;

/**
 * Placeholder route intercepted by Symfony login_link authenticator.
 */
final class MagicLoginCheckController
{
    public function check(): never
    {
        throw new LogicException('This method is intercepted by the login_link key on your firewall.');
    }
}
