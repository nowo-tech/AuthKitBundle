<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder route intercepted by Symfony login_link authenticator.
 */
final class MagicLoginCheckController
{
    #[Route(path: '/magic-login/check', name: 'nowo_auth_kit_magic_login_check', methods: ['GET'])]
    public function check(): never
    {
        throw new LogicException('This method is intercepted by the login_link key on your firewall.');
    }
}
