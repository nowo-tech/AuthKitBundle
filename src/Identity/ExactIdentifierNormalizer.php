<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Identity;

use Nowo\AuthKitBundle\Security\ExactIdentifier;

/**
 * Default {@see UserIdentifierNormalizerInterface} (1.48.0): refuses a value that can never be an exact key (the
 * {@see ExactIdentifier} rules: empty, invalid UTF-8, edge white space of any kind, control / format / separator
 * characters, longer than {@see self::MAX_LENGTH} characters) and returns every other value unchanged, so lookups behave
 * as before 1.48.0 (the column's collation decides case and accent matching, the re-check stays NFC + lower case).
 * Stateless (worker safe).
 */
final readonly class ExactIdentifierNormalizer implements UserIdentifierNormalizerInterface
{
    /** Upper bound in characters; generous on purpose (identifier columns are rarely wider than 255). */
    public const MAX_LENGTH = 1024;

    public function normalize(string $raw): ?string
    {
        return ExactIdentifier::usable($raw, self::MAX_LENGTH) ? $raw : null;
    }
}
