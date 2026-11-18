<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.53.0 (REQ-RUNTIME-003 rule 6): the commit ledger AuthKit's conflict answer reads to tell CONFLICT_002 (part of the
 * request already committed) from CONFLICT_001. The ledger of NowoTransactionDoctrineBundle (`commit_detection`) when
 * the host has it, so the DBAL connections are wrapped once; otherwise, with `conflict_response.commit_detection`
 * (default true), AuthKit's own ledger and transaction-doctrine's commit tracking middleware on the application's
 * connections. Registered before DoctrineBundle's middleware pass reads the `doctrine.middleware` tag.
 */
final class ConflictResponsePass implements CompilerPassInterface
{
    /** Set by NowoAuthKitExtension from `conflict_response.commit_detection`. */
    public const COMMIT_DETECTION_PARAMETER = 'nowo_auth_kit.conflict_response.commit_detection';

    /** The ledger the conflict answer reads (an alias of the host's, or AuthKit's own). */
    public const LEDGER = 'nowo_auth_kit.commit_ledger';

    /** AuthKit's commit tracking middleware, registered only without the host's. */
    public const MIDDLEWARE = 'nowo_auth_kit.commit_tracking_middleware';

    /** NowoTransactionDoctrineBundle's ledger (registered with its commit_detection on). */
    public const HOST_LEDGER = 'nowo_transaction_doctrine.commit_ledger';

    /** Priority among the before-optimization passes: before DoctrineBundle's middleware pass (0). */
    public const PRIORITY = 20;

    public function process(ContainerBuilder $container): void
    {
        if ($container->has(self::HOST_LEDGER)) {
            $container->setAlias(self::LEDGER, self::HOST_LEDGER);

            return;
        }
        if ($container->hasParameter(self::COMMIT_DETECTION_PARAMETER) && $container->getParameter(self::COMMIT_DETECTION_PARAMETER) === true) {
            $container->register(self::LEDGER, CommitLedger::class);
            $container->register(self::MIDDLEWARE, CommitTrackingMiddleware::class)
                ->setArguments([new Reference(self::LEDGER)])
                ->addTag('doctrine.middleware');
        }
    }
}
