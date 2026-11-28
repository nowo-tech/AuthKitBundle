<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * Adds a probe listener to a booted kernel's dispatcher.
 *
 * Symfony 8.2 deprecates `addListener()` on the compiled dispatcher (listeners belong in the container or in a
 * `ScopedEventDispatcher`). The tests observe the kernel's own dispatcher on purpose (the code under test dispatches on
 * it), so the 8.2 notice for this one call is expected and stays out of the deprecation report.
 */
final class KernelListeners
{
    /**
     * @param callable $listener
     */
    public static function add(EventDispatcherInterface $dispatcher, string $eventName, callable $listener, int $priority = 0): void
    {
        set_error_handler(static fn (int $level, string $message): bool => str_contains($message, 'ScopedEventDispatcher'), E_USER_DEPRECATED);

        try {
            $dispatcher->addListener($eventName, $listener, $priority);
        } finally {
            restore_error_handler();
        }
    }
}
