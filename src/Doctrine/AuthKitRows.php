<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;

use function bin2hex;
use function random_bytes;
use function reset;

/**
 * 1.50.0 (Beacon spec 239): writes the row of a new AuthKit entity whose unique key another request may take at the same
 * moment (an MFA credential of an owner, a QR login challenge's public code), through DBAL inside a savepoint. When the
 * unique key refuses it, only the savepoint is rolled back: the caller's transaction, the entity manager and every
 * entity the request holds are untouched, and the caller reads the row the other request wrote (a locking re-read). An
 * ORM flush that hits the key would close the entity manager instead.
 *
 * The entity is never managed by this call. Its columns come from the mapping (fields only: the entities written here
 * have no association and no encrypted value set at creation); ids from a sequence are asked first.
 *
 * Inside a transaction only (the units of work of {@see AuthKitTransactionRunner}).
 * Stateless.
 *
 * @internal
 */
final class AuthKitRows
{
    /** Prefix of the savepoints: never a level of the private manager's unit of work (PrivateEntityManagerConnection). */
    private const SAVEPOINT = 'nowo_auth_kit_row_';

    /**
     * @param EntityManagerInterface $entityManager The manager of the AuthKit rows
     * @param object $entity A new AuthKit entity (not persisted)
     *
     * @return UniqueConstraintViolationException|null What refused the row (another request wrote it first); null when
     *                                                 this call wrote it
     */
    public static function insert(EntityManagerInterface $entityManager, object $entity): ?UniqueConstraintViolationException
    {
        $metadata   = $entityManager->getClassMetadata($entity::class);
        $connection = $entityManager->getConnection();
        $row        = [];
        $types      = [];
        $generator  = $metadata->idGenerator;
        if (!$generator->isPostInsertGenerator() && $metadata->getIdentifierValues($entity) === []) {
            $metadata->setIdentifierValues($entity, [$metadata->getSingleIdentifierFieldName() => $generator->generateId($entityManager, $entity)]);
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($generator->isPostInsertGenerator() && $metadata->isIdentifier($field)) {
                continue;
            }
            $row[$mapping->columnName]   = $metadata->getFieldValue($entity, $field);
            $types[$mapping->columnName] = $mapping->type;
        }

        $savepoint = self::SAVEPOINT . bin2hex(random_bytes(4));
        $connection->createSavepoint($savepoint);
        try {
            $connection->insert($metadata->getTableName(), $row, $types);
        } catch (UniqueConstraintViolationException $lost) {
            $connection->rollbackSavepoint($savepoint);

            return $lost;
        }
        if ($connection->getDatabasePlatform()->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($savepoint);
        }
        if ($entityManager instanceof AuthKitEntityManager) {
            // The private manager's row was written beside its unit of work, at this transaction level.
            $entityManager->noteWrite();
        }

        return null;
    }

    /**
     * 2.2.1: the id column of a row written through a DBAL `insert()` of an entity's table. An id generated before the
     * insert (a sequence: PostgreSQL with DBAL 3) is asked now and written explicitly; an identity column (MySQL,
     * MariaDB, PostgreSQL with DBAL 4, SQLite) fills it itself, so nothing is added.
     *
     * @param class-string $class
     *
     * @return array<string, mixed> `[]`, or `[<id column> => <next id>]`
     */
    public static function preInsertId(EntityManagerInterface $entityManager, string $class): array
    {
        $metadata  = $entityManager->getClassMetadata($class);
        $generator = $metadata->idGenerator;
        if ($generator->isPostInsertGenerator()) {
            return [];
        }

        return [$metadata->getSingleIdentifierColumnName() => $generator->generateId($entityManager, null)];
    }

    /**
     * 2.2.1: the id of the row just written with {@see self::preInsertId()}: the generated one, or the connection's last
     * insert id for an identity column.
     *
     * @param array<string, mixed> $preInsertId
     */
    public static function insertedId(EntityManagerInterface $entityManager, array $preInsertId): int
    {
        return (int) ($preInsertId === [] ? $entityManager->getConnection()->lastInsertId() : reset($preInsertId));
    }
}
