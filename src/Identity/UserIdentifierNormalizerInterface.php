<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Identity;

/**
 * Normalises a user identifier before AuthKit looks a user up by it (1.49.0). Framework-free port (REQ-ARCH-001):
 * strings in, strings out.
 *
 * Applied to every identifier AuthKit looks a user up by (password sign-in form, magic login, password reset request
 * and code, social e-mail match, QR completion, MFA admin reset, admin creation) and to the identifier AuthKit itself
 * stores when it creates an account (registration, invitation, admin, social). It lets a host move its identifier column
 * to a binary collation (`utf8mb4_0900_bin`, REQ-SEC-004 / REQ-SEC-006): the database then matches the normalised value
 * byte for byte.
 *
 * Contract:
 * - return null to refuse the value: AuthKit then finds no user (no query is sent);
 * - be idempotent: `normalize(normalize($x)) === normalize($x)`;
 * - never map two values that can belong to two different stored accounts to the same result (no look-alike folding:
 *   no accent stripping, no Unicode case folding). The stored identifiers must already be in normalised form;
 * - be stateless: one instance serves every request of a FrankenPHP worker (REQ-RUNTIME-002).
 *
 * Built-in: {@see ExactIdentifierNormalizer} (default, refuses unusable values and changes nothing else) and
 * {@see LowercaseAsciiEmailIdentifierNormalizer} (opt-in, `nowo_auth_kit.identifier_normalizer: lowercase_ascii_email`).
 */
interface UserIdentifierNormalizerInterface
{
    /** The identifier to look up (and store), or null when the value is refused. */
    public function normalize(string $raw): ?string;
}
