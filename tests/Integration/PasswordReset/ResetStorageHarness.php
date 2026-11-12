<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\AuthKitBundle\Doctrine\PasswordResetTableInvalidationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetTokenInvalidationListener;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManager;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use Nowo\AuthKitBundle\PasswordReset\TablePasswordResetTokenManager;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\PasswordResetRequestRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PropertyAccess\PropertyAccess;

use function dirname;

/**
 * Both reset credential storages over one in-memory SQLite manager: the host users (ResetUser with an id, KernelUser
 * keyed by its e-mail) and the AuthKit reset table, with the onFlush invalidation listeners of both storages.
 */
final class ResetStorageHarness
{
    public readonly SqliteManagerRegistry $doctrine;

    public readonly EntityManagerInterface $em;

    public readonly MockClock $clock;

    public readonly ProfileRegistry $profiles;

    public readonly PasswordResetTokenManagerInterface $manager;

    public readonly PasswordResetRequestRepository $requests;

    /**
     * @param 'table'|'user_entity' $storage
     * @param array<string, mixed> $passwordReset
     */
    public function __construct(string $storage, string $delivery = 'link', string $userClass = ResetUser::class, array $passwordReset = [], string $tablePrefix = '')
    {
        $root           = dirname(__DIR__, 3);
        $this->doctrine = new SqliteManagerRegistry(['default' => [
            'Nowo\\AuthKitBundle\\Tests\\Integration\\PasswordReset\\Entity' => __DIR__ . '/Entity',
            'Nowo\\AuthKitBundle\\Tests\\Integration\\Kernel\\Entity'        => $root . '/tests/Integration/Kernel/Entity',
            'Nowo\\AuthKitBundle\\Entity'                                    => $root . '/src/Entity',
        ]]);
        $em = $this->doctrine->getManager();
        $this->assertEntityManager($em);
        $this->em       = $em;
        $this->clock    = new MockClock(new DateTimeImmutable('2026-11-12 10:00:00'));
        $this->profiles = ProfileRegistryFactory::single($userClass, ['password_reset' => ['mode' => 'enabled', 'delivery' => $delivery, 'storage' => $storage] + $passwordReset]);

        $accessor = PropertyAccess::createPropertyAccessor();
        if ($tablePrefix !== '') {
            $this->em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener($tablePrefix));
        }
        $this->requests = new PasswordResetRequestRepository($this->doctrine);
        $this->em->getEventManager()->addEventListener(Events::onFlush, new PasswordResetTokenInvalidationListener($this->profiles));
        $this->em->getEventManager()->addEventListener(Events::onFlush, new PasswordResetTableInvalidationListener($this->profiles, $this->requests, $accessor));

        $metadata = [];
        foreach ([ResetUser::class, KernelUser::class, PasswordResetRequest::class] as $class) {
            $metadata[] = $this->em->getClassMetadata($class);
        }
        (new SchemaTool($this->em))->createSchema($metadata);

        $limiter  = new AuthKitAttemptLimiter(new ArrayAdapter());
        $resolver = new PasswordResetUserResolver($this->em, $this->profiles);
        $legacy   = new PasswordResetTokenManager($this->em, $accessor, $resolver, $this->profiles, $this->clock, $limiter);
        $table    = new TablePasswordResetTokenManager($this->requests, new DoctrineUserLookup($this->em), $accessor, $resolver, $this->profiles, $this->clock, $limiter);

        $this->manager = $storage === 'table' ? new ProfilePasswordResetTokenManager($this->profiles, $legacy, $table) : $legacy;
    }

    public function addUser(string $email): ResetUser|KernelUser
    {
        $class = $this->profiles->getDefault()->userClass;
        $user  = $class === KernelUser::class ? (new KernelUser())->setEmail($email)->setPassword('initial') : new ResetUser($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function count(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }

    public function resetTable(): string
    {
        return $this->em->getClassMetadata(PasswordResetRequest::class)->getTableName();
    }

    /**
     * @phpstan-assert EntityManagerInterface $em
     */
    private function assertEntityManager(object $em): void
    {
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('Expected an entity manager.');
        }
    }
}
