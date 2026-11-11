<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

/**
 * The login target kernel with the expired-session 401 off (the default): background calls get the firewall's 302 and
 * the guard keeps them from replacing the saved target.
 */
final class LoginTargetPreserveTestKernel extends LoginTargetTestKernel
{
    protected const SESSION_EXPIRED = false;
}
