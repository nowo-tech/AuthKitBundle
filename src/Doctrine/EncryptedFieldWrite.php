<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Nowo\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;

use function str_ends_with;
use function strlen;

/**
 * 2.8.1: tells a value change of an `#[Encrypted]` property from the encryption layer swapping its stored form.
 *
 * nowo-tech/doctrine-encrypt-bundle writes an encrypted property through its setter when there is one (its default
 * `property_access: auto`): the plaintext after a load (`postLoad`) and after a flush (`postFlush`), the stored
 * ciphertext back before a flush (`preFlush`), a new ciphertext for an insert (`onFlush`). Those writes are not changes
 * of the entity: a setter that stamped `updatedAt` on them made every loaded row dirty (a host transaction runner then
 * refused the unit of work, and any flush rewrote `updated_at`). The setters of AuthKit's encrypted properties stamp
 * `updatedAt` only when {@see self::changes()} answers true.
 *
 * A value carrying the encryption marker is the stored form. One edge: with `on_decrypt_failure: keep`, a value that
 * could not be decrypted stays in its stored form, and replacing it through the setter keeps the new value but does not
 * stamp `updatedAt`. Stateless (worker-safe).
 *
 * @internal
 */
final class EncryptedFieldWrite
{
    /**
     * Whether writing $new over $current is a change of the entity (not the same value, and neither side is the stored
     * ciphertext the encryption layer swaps in or out).
     */
    public static function changes(?string $current, ?string $new): bool
    {
        return $current !== $new && !self::isStoredForm($current) && !self::isStoredForm($new);
    }

    private static function isStoredForm(?string $value): bool
    {
        $marker = DoctrineEncryptSubscriber::ENCRYPTION_MARKER;

        return $value !== null && strlen($value) > strlen($marker) && str_ends_with($value, $marker);
    }
}
