<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use RuntimeException;

use function in_array;
use function str_ends_with;
use function str_starts_with;

/**
 * One host rule, written once and implemented through both the deprecated ports ({@see LegacyRulePorts}) and their
 * 1.46 twins ({@see TwinRulePorts}), for the equivalence matrix.
 */
final class PortRule
{
    /**
     * @param array<string> $roles
     *
     * @throws RuntimeException for mallory (an exception must propagate unchanged)
     */
    public static function allows(string $identifier, array $roles, string $profile, string $subjectEmail = '', string $action = ''): bool
    {
        if (str_starts_with($identifier, 'mallory')) {
            throw new RuntimeException('host rule failed for ' . $profile);
        }
        if ($action === 'revoke' && !in_array('ROLE_ADMIN', $roles, true)) {
            return false;
        }
        if ($subjectEmail !== '' && str_ends_with($subjectEmail, '@blocked.test')) {
            return false;
        }

        return $identifier === 'alice@example.com' || ($profile === 'default' && in_array('ROLE_ADMIN', $roles, true));
    }
}
