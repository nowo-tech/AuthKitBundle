<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Throwable;

/**
 * A host listener failing on `LoginSuccessEvent`, after the token is set.
 */
final class ThrowingLoginListener
{
    public ?Throwable $throw = null;

    public function onLoginSuccess(): void
    {
        if ($this->throw instanceof Throwable) {
            throw $this->throw;
        }
    }
}
