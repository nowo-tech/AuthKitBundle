<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use function base64_encode;
use function hash;
use function hash_equals;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Invitation link tokens: 32 random bytes in base64url (sent once in the link, never stored); the database keeps only
 * their SHA-256, compared in constant time.
 */
final class InvitationTokens
{
    /** Route requirement of a token (base64url of 32 bytes, unpadded). */
    public const PATTERN = '[A-Za-z0-9_-]{43}';

    /**
     * @return array{0: string, 1: string} the token for the link and the hash to store
     */
    public function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$token, $this->hash($token)];
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Constant-time comparison of a token hash with the stored one (empty never matches). */
    public function matchesHash(string $tokenHash, string $storedHash): bool
    {
        return $storedHash !== '' && $tokenHash !== '' && hash_equals($storedHash, $tokenHash);
    }
}
