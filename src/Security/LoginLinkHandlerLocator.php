<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * The Symfony login link handler of each firewall (`security.authenticator.login_link_handler.<firewall>`), so magic
 * links are signed and consumed by the firewall of the profile they belong to (SEC-004 L2) instead of `main`.
 */
final class LoginLinkHandlerLocator
{
    public function __construct(
        private readonly ContainerInterface $handlers,
    ) {
    }

    public function get(string $firewall): ?LoginLinkHandlerInterface
    {
        if (!$this->handlers->has($firewall)) {
            return null;
        }

        $handler = $this->handlers->get($firewall);

        return $handler instanceof LoginLinkHandlerInterface ? $handler : null;
    }
}
