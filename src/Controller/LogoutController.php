<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;

/**
 * Placeholder route intercepted by the Symfony logout listener.
 */
final class LogoutController
{
    public function logout(): never
    {
        throw new LogicException('This method is intercepted by the logout key on your firewall.');
    }
}
