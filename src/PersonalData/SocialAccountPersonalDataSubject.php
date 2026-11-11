<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;

use function mb_strlen;
use function sprintf;
use function str_contains;

/**
 * Whose social links are exported (1.45, GDPR ports). Links reference the user by the profile's user class and a user
 * id ({@see SocialLoginAccount}):
 *
 * - {@see self::fromUserId()}: the owner id (Doctrine identifier, else the user identifier); at most 64 characters.
 * - {@see self::fromLegacyIdentifier()}: links stored before 1.37 for an object id, which hold the user identifier in
 *   both `user_id` and `user_identifier` (both must equal it); at most 255 characters.
 *
 * Values are kept as given (no trimming, no case folding); empty values and NUL bytes are rejected.
 */
final readonly class SocialAccountPersonalDataSubject
{
    public const USER_ID_MAX_LENGTH = 64;

    public const MAX_LENGTH = 255;

    private function __construct(
        public string $userClass,
        public string $value,
        public bool $legacy,
    ) {
    }

    public static function fromUserId(string $userClass, string $userId): self
    {
        return new self(self::checked($userClass, 'user class', self::MAX_LENGTH), self::checked($userId, 'user id', self::USER_ID_MAX_LENGTH), false);
    }

    public static function fromLegacyIdentifier(string $userClass, string $identifier): self
    {
        return new self(self::checked($userClass, 'user class', self::MAX_LENGTH), self::checked($identifier, 'user identifier', self::MAX_LENGTH), true);
    }

    private static function checked(string $value, string $what, int $max): string
    {
        if ($value === '' || str_contains($value, "\0") || mb_strlen($value) > $max) {
            throw new InvalidArgumentException(sprintf('A social account personal-data subject needs a non-empty %s of at most %d characters without NUL bytes.', $what, $max));
        }

        return $value;
    }
}
