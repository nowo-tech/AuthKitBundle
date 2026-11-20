<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\AuthKitBundle\Tests\Support\HostModeNotices;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.53.0 (REQ-RUNTIME-003 rule 6): `conflict_response` (status 409 or 503, Retry-After, commit detection) and the commit
 * ledger of the conflict answer (the host's NowoTransactionDoctrineBundle ledger, else AuthKit's own middleware).
 */
final class ConflictResponseConfigTest extends TestCase
{
    use HostModeNotices;

    public function testUnsetOptionsFollowTheHostsPolicyOrTheDefaults(): void
    {
        $container = $this->load([]);

        [$policy, $ledger] = $container->getDefinition(StorageFailureSubscriber::class)->getArguments();
        self::assertInstanceOf(Reference::class, $policy);
        self::assertSame(NowoAuthKitExtension::HOST_CONFLICT_POLICY, (string) $policy);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $policy->getInvalidBehavior());
        self::assertInstanceOf(Reference::class, $ledger);
        self::assertSame(ConflictResponsePass::LEDGER, (string) $ledger);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $ledger->getInvalidBehavior());
        self::assertTrue($container->getParameter(ConflictResponsePass::COMMIT_DETECTION_PARAMETER));
    }

    public function testAHostKeepsThe503(): void
    {
        $policy = $this->policy($this->load(['status' => 503]));
        self::assertSame([503, 1], $policy->getArguments());

        $policy = $this->policy($this->load(['retry_after' => 5]));
        self::assertSame([409, 5], $policy->getArguments());
        self::assertSame(ConflictResponsePolicy::class, $policy->getClass());
    }

    public function testOtherStatusesAndNegativeRetryAfterAreRefused(): void
    {
        foreach ([['status' => 500], ['retry_after' => -1]] as $option) {
            try {
                $this->load($option);
                self::fail('refused: ' . json_encode($option));
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('conflict_response', $exception->getMessage());
            }
        }
    }

    public function testWithoutTheHostsLedgerAuthKitTracksTheCommitsItself(): void
    {
        $container = $this->load([]);
        (new ConflictResponsePass())->process($container);

        self::assertSame(CommitLedger::class, $container->getDefinition(ConflictResponsePass::LEDGER)->getClass());
        $middleware = $container->getDefinition(ConflictResponsePass::MIDDLEWARE);
        self::assertSame(CommitTrackingMiddleware::class, $middleware->getClass());
        self::assertTrue($middleware->hasTag('doctrine.middleware'));
        self::assertEquals([new Reference(ConflictResponsePass::LEDGER)], $middleware->getArguments());
    }

    public function testTheHostsLedgerIsReusedAndTheConnectionsAreWrappedOnce(): void
    {
        $container = $this->load([]);
        $container->setDefinition(ConflictResponsePass::HOST_LEDGER, new Definition(CommitLedger::class));
        (new ConflictResponsePass())->process($container);

        self::assertSame(ConflictResponsePass::HOST_LEDGER, (string) $container->getAlias(ConflictResponsePass::LEDGER));
        self::assertFalse($container->hasDefinition(ConflictResponsePass::MIDDLEWARE));
    }

    public function testCommitDetectionOffRegistersNoLedger(): void
    {
        $container = $this->load(['commit_detection' => false]);
        (new ConflictResponsePass())->process($container);
        self::assertFalse($container->has(ConflictResponsePass::LEDGER));
        self::assertFalse($container->has(ConflictResponsePass::MIDDLEWARE));

        $bare = new ContainerBuilder();
        (new ConflictResponsePass())->process($bare);
        self::assertFalse($bare->has(ConflictResponsePass::LEDGER), 'the bundle not loaded');
    }

    /**
     * @param array<string, mixed> $conflictResponse
     */
    private function load(array $conflictResponse): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User', 'conflict_response' => $conflictResponse]], $container);

        return $container;
    }

    private function policy(ContainerBuilder $container): Definition
    {
        $policy = $container->getDefinition(StorageFailureSubscriber::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);

        return $policy;
    }
}
