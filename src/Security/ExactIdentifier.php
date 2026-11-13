<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;

/**
 * Identities used as database keys (REQ-SEC-006, 1.46.1): an MFA owner id, a social provider subject, a vault user
 * identifier, an invitation inviter. They are matched byte for byte, so a value that cannot be told apart from another
 * one on screen or by a folding collation is refused instead of being trimmed or truncated: edge whitespace (NBSP
 * included), control and format characters (ZWSP, BOM, bidi marks, line separators), invalid UTF-8, longer than the
 * column. Unicode normalisation forms are different ids, never the same one.
 *
 * @internal
 */
final class ExactIdentifier
{
    private function __construct()
    {
    } // @codeCoverageIgnore

    /** Whether `$value` can be stored and looked up as a key of at most `$maxLength` characters. */
    public static function usable(string $value, int $maxLength): bool
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
            return false;
        }

        // Edge white space of any kind (\s, NBSP, other Unicode spaces); control / format / separator characters anywhere.
        return preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/uD', $value) !== 1
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1;
    }
}
