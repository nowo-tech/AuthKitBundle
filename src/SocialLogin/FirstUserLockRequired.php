<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Exception;

/**
 * 1.50.0 (audit QG-M1): a social sign-up unit is about to create the first account of a `first_user_only` profile
 * without holding the user class lock; {@see SocialAccountLinker} rolls it back and runs it again under that lock.
 * Never leaves the linker.
 *
 * @internal
 */
final class FirstUserLockRequired extends Exception
{
    public function __construct()
    {
        parent::__construct('The first account of a first_user_only profile is created under the user class lock.');
    }
}
