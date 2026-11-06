<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use RuntimeException;

/**
 * A host listener failing on `LoginSuccessEvent` before the session strategy and UserKit ran; the token is already set.
 */
final class ThrowingLoginListener
{
    public bool $armed = false;

    public function onLoginSuccess(): void
    {
        if ($this->armed) {
            throw new RuntimeException('listener down');
        }
    }
}
