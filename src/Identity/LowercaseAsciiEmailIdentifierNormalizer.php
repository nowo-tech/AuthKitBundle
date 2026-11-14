<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Identity;

use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr_count;

/**
 * Opt-in {@see UserIdentifierNormalizerInterface} (1.49.0, `nowo_auth_kit.identifier_normalizer:
 * lowercase_ascii_email`): after the {@see ExactIdentifierNormalizer} refusals, a value that looks like an e-mail
 * address (exactly one `@`, text on both sides) has its ASCII letters A-Z lower-cased; every other byte is kept
 * (no Unicode case folding, no accent stripping, no trimming). A value that is not an e-mail address is returned
 * unchanged.
 *
 * Precondition: the stored identifiers are lower-case ASCII already (see UPGRADING "To 1.49.0" for the detection query),
 * so `Bob@Example.com` and `bob@example.com` cannot be two accounts. Then the lookup can run on a binary collation.
 * Stateless (worker safe).
 */
final readonly class LowercaseAsciiEmailIdentifierNormalizer implements UserIdentifierNormalizerInterface
{
    public function __construct(private ExactIdentifierNormalizer $exact = new ExactIdentifierNormalizer())
    {
    }

    public function normalize(string $raw): ?string
    {
        $value = $this->exact->normalize($raw);
        if ($value === null || substr_count($value, '@') !== 1 || str_starts_with($value, '@') || str_ends_with($value, '@')) {
            return $value;
        }

        // PHP 8.2+: strtolower() is locale-independent and only maps A-Z; multibyte UTF-8 sequences are untouched.
        return strtolower($value);
    }
}
