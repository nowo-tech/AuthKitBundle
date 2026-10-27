<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder route intercepted by the Symfony logout listener.
 */
final class LogoutController
{
    #[Route(path: '/logout', name: 'nowo_auth_kit_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('This method is intercepted by the logout key on your firewall.');
    }
}
