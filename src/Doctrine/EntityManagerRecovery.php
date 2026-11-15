<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_search;
use function in_array;
use function is_string;
use function spl_object_id;

/**
 * Reopens the entity managers closed by a failed bundle write, at the next unit-of-work boundary.
 *
 * Without a kernel reset between requests (FrankenPHP worker mode), a closed manager would
 * otherwise make every later request served by the same worker fail. DoctrineBundle resets
 * lazy managers in place, so services holding the manager keep a usable reference.
 *
 * Only the bundle's own managers are reset (1.43.0): the manager the failed write went through, the manager(s) of
 * the AuthKit entities (getManagerForClass()) and, when set, `nowo_auth_kit.doctrine.entity_manager`. Other closed
 * managers of the registry belong to the host and are left as they are.
 *
 * 1.50.0 (Beacon spec 239): a closed manager is no longer reset in the middle of the request that closed it. Resetting
 * it there detached every entity the application had loaded (the signed-in user, its own aggregates), so the
 * application's later changes were silently lost; the manager now stays closed for the rest of that request (the
 * application's next flush fails loudly) and {@see self::reset()} replaces it at the next request, message or command
 * boundary ({@see PrivateEntityManagerSubscriber}) or at `kernel.reset`. AuthKit's writes go through
 * {@see AuthKitTransactionRunner}; {@see self::run()} and {@see self::flush()} stay for
 * compatibility.
 */
final class EntityManagerRecovery implements ResetInterface
{
    /** @var list<class-string> */
    private const BUNDLE_ENTITIES = [
        QrLoginChallenge::class,
        SocialLoginCredential::class,
        SocialLoginAccount::class,
        Invitation::class,
        MfaCredential::class,
        MfaRecoveryCode::class,
        MfaTrustedDevice::class,
        AccountVaultEntry::class,
    ];

    /** @var array<int, EntityManagerInterface> managers a failed write went through, until the next boundary */
    private array $failedThrough = [];

    public function __construct(
        private readonly ?ManagerRegistry $registry = null,
        private readonly ?string $entityManagerName = null,
    ) {
    }

    public function flush(EntityManagerInterface $entityManager): void
    {
        $this->run(static function () use ($entityManager): void {
            $entityManager->flush();
        }, $entityManager);
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     * @param EntityManagerInterface|null $entityManager the manager the write goes through, when not a bundle one
     *
     * @return T
     */
    public function run(callable $write, ?EntityManagerInterface $entityManager = null): mixed
    {
        try {
            return $write();
        } catch (Throwable $exception) {
            if ($entityManager instanceof EntityManagerInterface) {
                // 1.50.0: replaced at the next boundary (see the class description), never here.
                // @igor-ignore - Managers of this unit of work only; emptied by reset() at the next boundary.
                $this->failedThrough[spl_object_id($entityManager)] = $entityManager;
            }

            throw $exception;
        }
    }

    /**
     * At a unit-of-work boundary (and `kernel.reset`): replaces the bundle's closed managers.
     */
    public function reset(): void
    {
        $failedThrough = $this->failedThrough;
        // @igor-ignore - Managers of the unit of work that just ended.
        $this->failedThrough = [];
        foreach ($failedThrough as $manager) {
            $this->resetClosedManagers($manager);
        }
        $this->resetClosedManagers();
    }

    /**
     * Resets the closed managers among the bundle's own: $writtenThrough and the manager(s) of the AuthKit entities.
     */
    public function resetClosedManagers(?EntityManagerInterface $writtenThrough = null): void
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return;
        }

        $managers = $this->registry->getManagers();
        $owned    = $this->ownedManagerNames($this->registry, $managers, $writtenThrough);
        foreach ($managers as $name => $manager) {
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen() && in_array($name, $owned, true)) {
                $this->registry->resetManager($name);
            }
        }
    }

    /**
     * @param array<string, object> $managers
     *
     * @return list<string>
     */
    private function ownedManagerNames(ManagerRegistry $registry, array $managers, ?EntityManagerInterface $writtenThrough): array
    {
        $owned = $this->entityManagerName !== null ? [$this->entityManagerName] : [];
        foreach ($managers as $name => $manager) {
            if ($writtenThrough instanceof EntityManagerInterface && $manager === $writtenThrough) {
                $owned[] = (string) $name;
            }
        }
        // 1.43.0 (audit LD-AK-M1): the manager of the AuthKit entities is always owned; the option only adds to it.
        foreach (self::BUNDLE_ENTITIES as $class) {
            $manager = $registry->getManagerForClass($class);
            if (!$manager instanceof ObjectManager) {
                continue;
            }
            $name = array_search($manager, $managers, true);
            if (is_string($name)) {
                $owned[] = $name;
            }
        }

        return $owned;
    }
}
