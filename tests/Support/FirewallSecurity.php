<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * A `Security` helper whose firewall map always answers the given firewall config (unit tests).
 */
final class FirewallSecurity
{
    public static function with(?FirewallConfig $config): Security
    {
        $map = new class($config) extends FirewallMap {
            public function __construct(private readonly ?FirewallConfig $config)
            {
                parent::__construct(new Container(), []);
            }

            public function getFirewallConfig(Request $request): ?FirewallConfig
            {
                return $this->config;
            }
        };
        $container = new Container();
        $container->set('security.firewall.map', $map);

        return new Security($container);
    }
}
