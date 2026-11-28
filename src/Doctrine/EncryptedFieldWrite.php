<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Nowo\DoctrineEncryptBundle\Subscribers\DoctrineEncryptSubscriber;

use function str_ends_with;
use function strlen;

/**
 * 2.9.0: tells a value change of an `#[Encrypted]` property from the encryption layer swapping its stored form.
 *
 * nowo-tech/doctrine-encrypt-bundle writes an encrypted property through its setter when there is one (its default
 * `property_access: auto`): the plaintext after a load (`postLoad`) and after a flush (`postFlush`), the stored
 * ciphertext back before a flush (`preFlush`), a new ciphertext for an insert (`onFlush`). Those writes are not changes
 * of the entity: a setter that stamped `updatedAt` on them made every loaded row dirty (a host transaction runner then
 * refused the unit of work, and any flush rewrote `updated_at`). The setters of AuthKit's encrypted properties stamp
 * `updatedAt` only when {@see self::changes()} answers true.
 *
 * A value that ends with the encryption marker (`<ENC>`) is taken as the stored form, so a write from or to such a
 * value never stamps `updatedAt`. The value itself is always assigned and persisted (DoctrineEncrypt decides on its own
 * whether to encrypt it, and Doctrine computes the change set from the values, not from `updatedAt`); only the timestamp
 * is affected, in two edges:
 *
 * - a value that still ends with `<ENC>` in memory: no encryptor configured, or a DoctrineEncrypt older than 2.10
 *   whose `on_decrypt_failure: keep` left the stored form in place (since 2.10, `keep` puts the payload with the marker
 *   stripped in the property, so replacing it does stamp `updatedAt`);
 * - a real plaintext that ends with `<ENC>` (a client secret set to such a value, or a token an identity provider
 *   returns): written and encrypted as usual, but `updated_at` keeps its previous time.
 *
 * Stateless (worker-safe).
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
