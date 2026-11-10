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
use Throwable;

use function array_search;
use function in_array;
use function is_string;

/**
 * Runs bundle writes and reopens the entity managers closed by a failed bundle write.
 *
 * Without a kernel reset between requests (FrankenPHP worker mode), a closed manager would
 * otherwise make every later request served by the same worker fail. DoctrineBundle resets
 * lazy managers in place, so services holding the manager keep a usable reference.
 *
 * Only the bundle's own managers are reset (1.42.1): the manager the failed write went through and the manager(s)
 * of the AuthKit entities (`nowo_auth_kit.doctrine.entity_manager`, else resolved with getManagerForClass()).
 * Other closed managers of the registry belong to the host and are left as they are.
 */
final class EntityManagerRecovery
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
            $this->resetClosedManagers($entityManager);

            throw $exception;
        }
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
        if ($this->entityManagerName !== null) {
            return $owned;
        }

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
