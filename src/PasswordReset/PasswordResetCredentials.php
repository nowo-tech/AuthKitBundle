<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function bin2hex;
use function explode;
use function hash;
use function hash_equals;
use function is_string;
use function random_bytes;
use function random_int;
use function str_contains;
use function strlen;

/**
 * The reset credential rules shared by both storages (1.46.0: moved out of {@see PasswordResetTokenManager} unchanged,
 * so `password_reset.storage: user_entity` and `table` issue and check credentials the same way): plain token / code
 * generation, the stored hash form, the constant-time code check and the code attempt counter key. Stateless.
 *
 * @internal
 */
final class PasswordResetCredentials
{
    /**
     * The plain credential handed to the notifier: a hex link token (`token_bytes` random bytes), a code
     * (`code_length` characters of `code_charset`), or `token:code`.
     */
    public static function generate(ProfileSettings $profile, PasswordResetDeliveryMode $delivery): string
    {
        $settings = $profile->passwordReset;

        return match ($delivery) {
            PasswordResetDeliveryMode::Link => bin2hex(random_bytes($settings['token_bytes'])),
            PasswordResetDeliveryMode::Code => self::generateCode($profile),
            PasswordResetDeliveryMode::Both => bin2hex(random_bytes($settings['token_bytes'])) . ':' . self::generateCode($profile),
        };
    }

    /**
     * What is stored: the SHA-256 of the token or code, or `sha256(token)|sha256(code)` for `both`.
     */
    public static function storageValue(string $plain, PasswordResetDeliveryMode $delivery): string
    {
        return match ($delivery) {
            PasswordResetDeliveryMode::Link => hash('sha256', $plain),
            PasswordResetDeliveryMode::Code => hash('sha256', $plain),
            PasswordResetDeliveryMode::Both => hash('sha256', explode(':', $plain, 2)[0]) . '|' . hash('sha256', explode(':', $plain, 2)[1]),
        };
    }

    /**
     * Constant-time check of a submitted code against the stored value (its code part for `both`).
     */
    public static function codeMatches(string $stored, string $code): bool
    {
        $codeHash = hash('sha256', $code);

        if (str_contains($stored, '|')) {
            $parts = explode('|', $stored, 2);

            return hash_equals($parts[1], $codeHash);
        }

        return hash_equals($stored, $codeHash);
    }

    /**
     * SEC-004 M3: the code attempt counter belongs to the resolved account (its stored identifier), so spelling
     * variants of one identifier (case, Unicode composition) share a single max_code_attempts budget.
     */
    public static function codeAttemptKey(PropertyAccessorInterface $propertyAccessor, ProfileSettings $profile, ?object $user, string $identifier): string
    {
        $stored = $user !== null
            ? $propertyAccessor->getValue($user, $profile->userIdentifierField)
            : null;
        $key = is_string($stored) && $stored !== '' ? $stored : $identifier;

        return 'reset_code:' . $profile->name . ':' . hash('sha256', UserIdentifierMatcher::normalize($key));
    }

    private static function generateCode(ProfileSettings $profile): string
    {
        $settings = $profile->passwordReset;

        $charset = $settings['code_charset'] === 'alphanumeric'
            ? '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'
            : '0123456789';

        $maxIndex = strlen($charset) - 1;
        $code     = '';

        for ($i = 0; $i < $settings['code_length']; ++$i) {
            $code .= $charset[random_int(0, $maxIndex)];
        }

        return $code;
    }
}
