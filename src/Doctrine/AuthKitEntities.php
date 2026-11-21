<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function get_debug_type;
use function implode;
use function in_array;
use function is_scalar;

/**
 * 1.55.0 (REQ-ARCH-001 rule 6, Q-W2-1): AuthKit rows that the caller loaded through another entity manager. With
 * `doctrine.private_entity_manager: true` an invitation, a QR login challenge or an MFA credential the application
 * loaded through its own manager (a host repository, a route value resolver, `getReference()`) is not held by AuthKit's
 * manager, and `persist()` would treat it as new: a second INSERT that the unique keys refuse. The AuthKit services
 * therefore write with the instance AuthKit's manager holds for the same id, loaded again when needed.
 *
 * Only AuthKit's manager is used: the caller's manager and its instance are never flushed, merged, detached, refreshed
 * or written to. Stateless (worker safe).
 *
 * @internal
 */
final class AuthKitEntities
{
    /**
     * The instance AuthKit's manager holds for the caller's row: the same object when the manager holds it (always
     * without the private manager, and for rows read through AuthKit's repositories), otherwise the row loaded again by
     * id. An object without an identifier yet (a new entity) is returned unchanged.
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager AuthKit's manager of the class
     * @param T $entity The object the caller holds
     *
     * @throws AuthKitEntityNotFoundException When the row no longer exists
     *
     * @return T The instance to read and write with
     */
    public static function adopt(EntityManagerInterface $entityManager, object $entity): object
    {
        if ($entityManager->contains($entity)) {
            return $entity;
        }
        $metadata = $entityManager->getClassMetadata($entity::class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === [] || in_array(null, $id, true)) {
            return $entity;
        }
        /** @var T|null $managed */
        $managed = $entityManager->find($metadata->getName(), $id);
        if ($managed === null) {
            throw new AuthKitEntityNotFoundException($metadata->getName(), self::idString($id));
        }

        return $managed;
    }

    /**
     * Refuses to save an existing row AuthKit's manager does not hold (it would be inserted again). A new entity passes:
     * one without an identifier yet, or one with an assigned identifier whose row does not exist.
     *
     * @param EntityManagerInterface $entityManager AuthKit's manager of the class
     * @param object $entity The object about to be persisted
     *
     * @throws AuthKitEntityNotManagedException When the row exists and the manager does not hold this instance
     * @throws AuthKitEntityNotFoundException When its generated identifier points to a row that no longer exists
     */
    public static function assertSavable(EntityManagerInterface $entityManager, object $entity): void
    {
        if ($entityManager->contains($entity)) {
            return;
        }
        $metadata = $entityManager->getClassMetadata($entity::class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === [] || in_array(null, $id, true)) {
            return;
        }
        if ($entityManager->find($metadata->getName(), $id) !== null) {
            throw new AuthKitEntityNotManagedException($metadata->getName(), self::idString($id));
        }
        if (!$metadata->isIdentifierNatural()) {
            throw new AuthKitEntityNotFoundException($metadata->getName(), self::idString($id));
        }
    }

    /**
     * @param array<string, mixed> $id
     */
    private static function idString(array $id): string
    {
        return implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : get_debug_type($value), $id));
    }
}
