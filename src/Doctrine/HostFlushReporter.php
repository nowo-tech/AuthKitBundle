<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.54.0 (REQ-ARCH-001 rule 6 deprecation layer): reports the application's pending changes that an AuthKit write
 * flushes in host mode (`doctrine.private_entity_manager` unset or false), through the host-flush probe of
 * nowo-tech/transaction-doctrine ({@see HostFlushProbe}). For each entity class outside `Nowo\AuthKitBundle\Entity` with
 * pending work in the application's manager, one `E_USER_DEPRECATED` per class and unit of work (request, message,
 * command): "Your pending changes to App\X were flushed by NowoAuthKitBundle; 2.0 no longer does.".
 *
 * Called by {@see AuthKitTransactionRunner} right before it flushes the manager of the
 * AuthKit rows when that manager is the application's (never AuthKit's private one). Only the OUTERMOST AuthKit unit of
 * work reports: inside it, the pending changes of the application's entities are AuthKit's own (an account the unit
 * writes, a password it changes), which the account writes still flush in 2.0. The depth is counted across every
 * runner wired to this service.
 *
 * Read-only: it never flushes, clears, closes or resets a manager (REQ-RUNTIME-003). The probe is the shared
 * `nowo_transaction_doctrine.host_flush_probe` when the application registers NowoTransactionDoctrineBundle, else one
 * of its own. Both the depth and the probe's "already reported" classes are reset at every AuthKit unit-of-work
 * boundary ({@see PrivateEntityManagerSubscriber}) and at `kernel.reset`, so a FrankenPHP worker with kernel reset off
 * starts every request clean.
 *
 * @internal
 */
final class HostFlushReporter implements ResetInterface
{
    /** The bundle named in the deprecation. */
    public const BUNDLE_NAME = 'NowoAuthKitBundle';

    /** AuthKit's entity namespace: its classes are never reported. */
    public const OWN_NAMESPACES = ['Nowo\\AuthKitBundle\\Entity'];

    private readonly HostFlushProbe $probe;

    /** AuthKit units of work running in this process (0: none). */
    private int $depth = 0;

    public function __construct(?HostFlushProbe $probe = null)
    {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    /**
     * An AuthKit unit of work starts; true when it is the outermost one (the only one that reports).
     */
    public function enter(): bool
    {
        // @igor-ignore - per unit of work, always paired with leave() (finally) and emptied by reset() at every boundary
        return $this->depth++ === 0;
    }

    /**
     * The unit of work that {@see self::enter()} started ended (however it ended).
     */
    public function leave(): void
    {
        if ($this->depth > 0) {
            // @igor-ignore - per unit of work, paired with enter()
            --$this->depth;
        }
    }

    /**
     * Right before AuthKit flushes the manager of its rows: reports the application's pending changes when that manager
     * is the application's. AuthKit's private manager holds no application change and is never reported on.
     */
    public function beforeFlush(EntityManagerInterface $entityManager): void
    {
        if ($entityManager instanceof AuthKitEntityManager) {
            return;
        }
        $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, self::OWN_NAMESPACES);
    }

    /** A new unit of work (request, message, command) starts: nothing is running, nothing was reported. */
    public function reset(): void
    {
        $this->depth = 0; // @igor-ignore - the boundary reset itself
        $this->probe->reset();
    }
}
