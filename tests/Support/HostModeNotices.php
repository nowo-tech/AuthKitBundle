<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\AuthKitPortsPassTest;
use Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\PrivateEntityManagerConfigTest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

use function preg_match;
use function preg_quote;
use function restore_error_handler;
use function set_error_handler;
use function strpos;
use function substr_replace;

use const E_USER_DEPRECATED;

/**
 * 1.54.0: for the kernel and container tests. Most run AuthKit in host mode on purpose (`doctrine.private_entity_manager`
 * unset, the 1.45 wiring under test), so their container build triggers the "is not set" notice; several kernels keep
 * test doubles (`Nowo\AuthKitBundle\Tests\`) on the deprecated 1.45 port interfaces for BC coverage, which trigger the
 * per-service port notice. Only these exact notices are expected here: every other deprecation, a port notice for a
 * class outside the test namespace included, still reaches PHPUnit and fails the run (REQ-SF-005).
 *
 * The notices are build-time notices, so they come from whichever test of the class compiles the cached kernel first;
 * they are expected for the whole class rather than asserted per test. {@see PrivateEntityManagerConfigTest}
 * and {@see AuthKitPortsPassTest} assert them.
 */
trait HostModeNotices
{
    #[Before]
    protected function expectHostModeNotices(): void
    {
        $patterns = ['/^' . preg_quote('Since nowo-tech/auth-kit-bundle 1.54: ' . NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION, '/') . '$/'];
        // Test doubles only: a port notice for a class outside the test namespace still fails.
        $pattern = preg_quote('Since nowo-tech/auth-kit-bundle 1.54: ' . AuthKitPortsPass::DEPRECATED_PORT, '/');
        foreach (['[^"]+', preg_quote('Nowo\\AuthKitBundle\\Tests\\', '/') . '[^)]+', '[^"]+', '[^"]+'] as $part) {
            $at      = strpos($pattern, '%s');
            $pattern = $at === false ? $pattern : substr_replace($pattern, $part, $at, 2);
        }
        $patterns[] = '/^' . $pattern . '$/';

        $previous = null;
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored by stopExpectingHostModeNotices(), #[After])
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $patterns): bool {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $message) === 1) {
                    return true;
                }
            }

            return $previous !== null && (bool) $previous($level, $message, $file, $line);
        }, E_USER_DEPRECATED);
    }

    #[After]
    protected function stopExpectingHostModeNotices(): void
    {
        restore_error_handler();
    }
}
