<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Closure;
use RuntimeException;
use Throwable;

/**
 * A host listener failing on `LoginSuccessEvent` before the session strategy and UserKit ran; the token is already set.
 */
final class ThrowingLoginListener
{
    public bool $armed = false;

    /** Runs late on `LoginSuccessEvent`, after UserKit and the switcher (it may throw). */
    public ?Closure $late = null;

    /** Thrown from `UserSessionsRevokedEvent`. */
    public ?Throwable $onRevoked = null;

    public function onLateLoginSuccess(): void
    {
        if ($this->late instanceof Closure) {
            $late       = $this->late;
            $this->late = null;
            $late();
        }
    }

    public function onRevokedEvent(): void
    {
        if ($this->onRevoked instanceof Throwable) {
            throw $this->onRevoked;
        }
    }

    public function onLoginSuccess(): void
    {
        if ($this->armed) {
            throw new RuntimeException('listener down');
        }
    }
}
