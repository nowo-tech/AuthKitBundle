<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_search;
use function in_array;
use function is_object;
use function is_string;
use function spl_object_id;

/**
 * 1.50.0 (Beacon spec 239): the runner of every AuthKit write, registered as `nowo_auth_kit.transaction_runner` and
 * injected into the AuthKit services by its own class. AuthKit never runs its writes through a runner the application
 * binds to {@see TransactionRunnerInterface}: such a runner drives the application's entity manager (it would reset or
 * clear it after an AuthKit failure). Modelled on ChatKit 1.30.0 (audits QD-M1 / QD-M3).
 *
 * One attempt, one transaction ({@see DoctrineTransactionRunner::forConnection()} of nowo-tech/transaction-doctrine): a
 * transient failure (deadlock, lock wait timeout, serialization failure) surfaces as
 * {@see TransientStorageFailure} (409 + CONFLICT_001 + Retry-After on AuthKit's routes). After any failure no
 * transaction level of its own stays open: the levels are rolled back to the entry level, and the connection is closed
 * only when that rollback fails (a MySQL deadlock inside a nested flush, where the server already dropped the
 * savepoints). The connection's nesting level is therefore never left raised for the next request of a worker.
 *
 * Managers:
 * - {@see self::run()}: the manager of the AuthKit rows (the private one with `doctrine.private_entity_manager: true`,
 *   else the application's). Its pending work is flushed first, on its own, so a failed AuthKit unit never rolls it
 *   back; the unit's changes are flushed inside the transaction.
 * - {@see self::runWithAccounts()}: the same, for a unit that may also write accounts (the application's user
 *   entities) through the application's manager: registration, invitation acceptance, social sign-up and linking,
 *   administrator creation, password reset. Inside the unit that manager is flushed only by the code that writes the
 *   accounts (the Doctrine user writer, the invitation grants), exactly as before 1.50.0; after a failure the rows the
 *   unit touched in it are detached too.
 * - {@see self::runOnConnection()}: a unit that writes through DBAL statements or the application's own user ports only
 *   (no AuthKit entity): no manager is flushed, before nor inside the unit (the host user ports store the accounts).
 *
 * A watched manager the runner does not flush first (the application's with the private manager, every manager of
 * {@see self::runOnConnection()}) may hold the caller's own pending changes: the runner never flushes it (REQ-ARCH-001
 * rule 6) and keeps them pending after a failed unit (audit QG-L1). It compares each row's field values with a copy
 * taken before the unit (without asking the ORM for change sets): a row the unit neither changed nor flushed stays
 * managed with its pending changes. A pending change of the caller that an account write of the unit flushed (the
 * Doctrine user writer flushes the whole manager) was rolled back with the unit: that row is detached.
 *
 * After a failure the managers are never reset nor cleared. Only the rows the unit added, wrote or changed are detached
 * (their database state was rolled back), with the rows that point at them; every other entity stays managed. A manager
 * the ORM closed in a failed flush stays closed for the rest of the request, so the application's next flush fails
 * loudly instead of silently losing its changes, and {@see self::reset()} replaces it at the next request, message or
 * command boundary: a FrankenPHP worker with kernel reset off never serves a later request with it.
 *
 * Host mode (1.54.0, REQ-ARCH-001 rule 6 deprecation layer): when the manager of the AuthKit rows is the application's
 * (`doctrine.private_entity_manager` unset or false), the outermost unit reports, right before each flush of that
 * manager, the application's pending changes it writes on the application's behalf ({@see HostFlushReporter}); 2.0 no
 * longer flushes them. Read-only: nothing else changes.
 *
 * Stateless (worker safe); the nesting depth lives in the shared {@see HostFlushReporter}, reset at every boundary.
 */
final readonly class AuthKitTransactionRunner implements TransactionRunnerInterface, ResetInterface
{
    /** The service id the AuthKit services are wired to. */
    public const SERVICE_ID = 'nowo_auth_kit.transaction_runner';

    /**
     * @param EntityManagerInterface $entityManager The manager of the AuthKit rows
     * @param EntityManagerInterface|null $applicationManager The manager of the accounts (the application's); null: the same
     * @param ManagerRegistry|null $registry Replaces a closed application manager at a boundary
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     * @param LoggerInterface|null $logger Warns when a closed manager is replaced at a boundary
     * @param HostFlushReporter|null $hostFlush 1.54.0: reports the application's pending changes a host-mode flush writes
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?EntityManagerInterface $applicationManager = null,
        private ?ManagerRegistry $registry = null,
        private ?int $retryAfterSeconds = 1,
        private ?LoggerInterface $logger = null,
        private ?HostFlushReporter $hostFlush = null,
    ) {
    }

    /**
     * A one-attempt runner on the connection only (no entity manager flushed or touched), for AuthKit units of work
     * written through DBAL statements.
     *
     * @param Connection $connection The connection of the AuthKit tables
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     */
    public static function forConnection(Connection $connection, ?int $retryAfterSeconds = 1): TransactionRunnerInterface
    {
        return DoctrineTransactionRunner::forConnection($connection, $retryAfterSeconds);
    }

    /**
     * The connection of the AuthKit rows (shared with the application's manager).
     */
    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Runs a unit of work that writes AuthKit rows only.
     *
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        return $this->runOn([$this->entityManager], $operation);
    }

    /**
     * Runs a unit of work that also writes accounts through the application's manager (see the class description).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function runWithAccounts(callable $operation): mixed
    {
        return $this->runOn([$this->entityManager], $operation, $this->accountManagers());
    }

    /**
     * Runs a unit of work that flushes no entity manager (DBAL statements, the application's own user ports); the rows it
     * touched in the managers are still detached after a failure.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function runOnConnection(callable $operation): mixed
    {
        return $this->runOn([], $operation, $this->accountManagers());
    }

    /**
     * At a request, message or command boundary: an application manager that a failed write closed is replaced, so the
     * next unit of work of a long-running worker starts with an open one. Nothing happens to an open manager, nor to
     * AuthKit's private manager (it replaces itself).
     */
    public function reset(): void
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return;
        }
        foreach ([$this->entityManager, $this->applicationManager] as $manager) {
            if (!$manager instanceof EntityManagerInterface || $manager instanceof AuthKitEntityManager || $manager->isOpen()) {
                continue;
            }
            $name = array_search($manager, $this->registry->getManagers(), true);
            if (!is_string($name)) {
                continue;
            }
            $this->registry->resetManager($name);
            $this->logger?->warning('AuthKit: a closed entity manager was replaced at the unit-of-work boundary.', ['bundle' => 'nowo_auth_kit', 'manager' => $name]);
        }
    }

    /**
     * @template T
     *
     * @param list<EntityManagerInterface> $managers Flushed first and inside the transaction
     * @param callable(): T $operation
     * @param list<EntityManagerInterface> $watched Whose touched rows are detached after a failure; [] = $managers
     *
     * @return T
     */
    private function runOn(array $managers, callable $operation, array $watched = []): mixed
    {
        $hostFlush = $this->hostFlush;
        // 1.54.0: only the outermost AuthKit unit reports host-mode flushes (see HostFlushReporter).
        $reporter = $hostFlush instanceof HostFlushReporter && $hostFlush->enter() ? $hostFlush : null;

        try {
            // Pending work first, on its own: a failed AuthKit unit never rolls it back.
            foreach ($managers as $manager) {
                $reporter?->beforeFlush($manager);
                $manager->flush();
            }
            $watched = $watched === [] ? $managers : $watched;
            $before  = [];
            $states  = [];
            foreach ($watched as $index => $manager) {
                $before[$index] = $this->rows($manager);
                // Audit QG-L1: a manager not flushed above may hold the caller's pending changes; remember the field values.
                $states[$index] = in_array($manager, $managers, true) ? null : $this->states($manager);
            }

            try {
                return DoctrineTransactionRunner::forConnection($this->entityManager->getConnection(), $this->retryAfterSeconds)->run(static function () use ($operation, $managers, $reporter): mixed {
                    $result = $operation();
                    foreach ($managers as $manager) {
                        $reporter?->beforeFlush($manager);
                        $manager->flush();
                    }

                    return $result;
                });
            } catch (Throwable $failure) {
                foreach ($watched as $index => $manager) {
                    // A manager the ORM closed in a failed flush stays closed: loud, and replaced at the next boundary.
                    if ($manager->isOpen()) {
                        $this->forget($manager, $before[$index], $states[$index]);
                    }
                }

                throw $failure;
            }
        } finally {
            $hostFlush?->leave();
        }
    }

    /**
     * The managers whose rows a unit that may write accounts can touch: AuthKit's and the application's.
     *
     * @return list<EntityManagerInterface>
     */
    private function accountManagers(): array
    {
        $managers = [$this->entityManager];
        if ($this->applicationManager instanceof EntityManagerInterface && $this->applicationManager !== $this->entityManager) {
            $managers[] = $this->applicationManager;
        }

        return $managers;
    }

    /**
     * The rows the manager holds, with the data it knows them by (before a unit of work).
     *
     * @return array<int, array<string, mixed>> object id => original data
     */
    private function rows(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $rows[spl_object_id($entity)] = $unitOfWork->getOriginalEntityData($entity);
        }
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $rows[spl_object_id($entity)] = $unitOfWork->getOriginalEntityData($entity);
            }
        }

        return $rows;
    }

    /**
     * The field values (and to-one targets) of the rows the manager holds, read through the class metadata only: no
     * change set is computed, so the ORM's own bookkeeping of the caller's pending changes is left as it was.
     *
     * @return array<int, array<string, mixed>|null> object id => values (null: a lazy row not loaded)
     */
    private function states(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $states     = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $states[spl_object_id($entity)] = $this->state($manager, $entity);
        }
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $states[spl_object_id($entity)] = $this->state($manager, $entity);
            }
        }

        return $states;
    }

    /**
     * @return array<string, mixed>|null null for a lazy row not loaded (reading it would load it)
     */
    private function state(EntityManagerInterface $manager, object $entity): ?array
    {
        if ($manager->getUnitOfWork()->isUninitializedObject($entity)) {
            return null;
        }
        $metadata = $manager->getClassMetadata($entity::class);
        $state    = [];
        foreach ($metadata->getFieldNames() as $field) {
            $state[$field] = $metadata->getFieldValue($entity, $field);
        }
        foreach ($metadata->associationMappings as $field => $mapping) {
            if ($mapping->isToOne()) {
                $state[$field] = $metadata->getFieldValue($entity, $field);
            }
        }

        return $state;
    }

    /**
     * After a failed unit: detaches the rows it added, wrote or changed (their database state was rolled back); a row
     * the caller held and the unit did not change stays managed. A row that points at a detached one goes too (it would
     * drag it back as a new entity on the next flush).
     *
     * A manager that was not flushed before the unit may hold the caller's own pending changes: there a row counts as
     * touched when the unit added it, flushed it or changed one of its values (audit QG-L1), never for a change the caller
     * made before the unit.
     *
     * @param array<int, array<string, mixed>> $before {@see self::rows()} before the unit
     * @param array<int, array<string, mixed>|null>|null $states {@see self::states()} before the unit; null when the
     *                                                           manager was flushed first (its pending changes are the unit's)
     */
    private function forget(EntityManagerInterface $manager, array $before, ?array $states): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $rows[] = $entity;
            }
        }
        $stale = [];
        $kept  = [];
        foreach ($rows as $entity) {
            $id = spl_object_id($entity);
            if (!isset($before[$id]) || $before[$id] !== $unitOfWork->getOriginalEntityData($entity) || $this->touched($manager, $entity, $states)) {
                $stale[$id] = $entity;
            } else {
                $kept[$id] = $entity;
            }
        }
        do {
            $more = false;
            foreach ($kept as $id => $entity) {
                $metadata = $manager->getClassMetadata($entity::class);
                foreach ($metadata->associationMappings as $field => $mapping) {
                    $target = $mapping->isToOne() ? $metadata->getFieldValue($entity, $field) : null;
                    if (is_object($target) && isset($stale[spl_object_id($target)])) {
                        $stale[$id] = $entity;
                        unset($kept[$id]);
                        $more = true;

                        break;
                    }
                }
            }
        } while ($more);
        foreach ($stale as $entity) {
            if ($manager->contains($entity)) {
                $manager->detach($entity);
            }
        }
    }

    /**
     * Whether the unit changed the row without flushing it.
     *
     * @param array<int, array<string, mixed>|null>|null $states {@see self::forget()}
     */
    private function touched(EntityManagerInterface $manager, object $entity, ?array $states): bool
    {
        if ($states === null) {
            return $this->changed($manager, $entity);
        }

        return ($states[spl_object_id($entity)] ?? null) !== $this->state($manager, $entity);
    }

    /**
     * Whether the row has changes the manager would write (the ORM's own comparison with the data it knows it by).
     */
    private function changed(EntityManagerInterface $manager, object $entity): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        if ($unitOfWork->isUninitializedObject($entity)) {
            return false;
        }
        $unitOfWork->computeChangeSet($manager->getClassMetadata($entity::class), $entity);

        return $unitOfWork->getEntityChangeSet($entity) !== [];
    }
}
