<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

use function array_chunk;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function is_int;
use function is_string;

/**
 * Exact row lookups of the GDPR ports (1.45, BUNDLES_FIX_RULES "Identifier columns"): the SQL `=` only pre-filters
 * (a case- or accent-insensitive collation also returns `UserA` for `usera`, `jose` for `josé`); every row is
 * re-checked with `===` in PHP. Rows are read in id order, {@see self::BATCH} at a time (keyset, so rows rejected by
 * the re-check never loop), and written back by primary key in batches.
 *
 * @internal
 */
final class ExactRows
{
    public const BATCH = 500;

    /**
     * @param array<string, string> $equals column => value every returned row holds exactly
     * @param list<string> $columns further columns to read
     * @param string|null $condition further SQL condition (constant, no parameters)
     *
     * @return list<array<string, mixed>> rows keyed by column, `id` included, in id order
     */
    public static function find(Connection $connection, string $table, array $equals, array $columns = [], ?string $condition = null): array
    {
        $select = array_values(array_unique(array_merge(['id'], array_keys($equals), $columns)));
        $found  = [];
        $last   = 0;
        do {
            $query = $connection->createQueryBuilder()
                ->select(...$select)
                ->from($table)
                ->where('id > :last')
                ->setParameter('last', $last, ParameterType::INTEGER)
                ->orderBy('id', 'ASC')
                ->setMaxResults(self::BATCH);
            foreach ($equals as $column => $value) {
                $query->andWhere($column . ' = :' . $column)->setParameter($column, $value);
            }
            if ($condition !== null) {
                $query->andWhere($condition);
            }
            $rows = $query->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if (self::matches($row, $equals)) {
                    $found[] = $row;
                }
            }
        } while (count($rows) === self::BATCH);

        return $found;
    }

    /**
     * Runs `$sql` (with an `:ids` placeholder) once per batch of ids; the sum of the affected rows.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $parameters
     * @param array<string, ParameterType|string> $types
     */
    public static function writeByIds(Connection $connection, string $sql, array $ids, array $parameters = [], array $types = []): int
    {
        $affected = 0;
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $affected += (int) $connection->executeStatement($sql, ['ids' => $batch] + $parameters, ['ids' => ArrayParameterType::INTEGER] + $types);
        }

        return $affected;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    public static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $equals
     */
    private static function matches(array $row, array $equals): bool
    {
        foreach ($equals as $column => $value) {
            $stored = $row[$column] ?? null;
            if (!(is_string($stored) || is_int($stored)) || (string) $stored !== $value) {
                return false;
            }
        }

        return true;
    }
}
