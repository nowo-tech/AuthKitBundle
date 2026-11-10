<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function dirname;

/**
 * Rule 6 (W0): a failed bundle write reopens the bundle's own entity manager only. A closed host manager stays the
 * host's business: the bundle never resets it.
 */
final class EntityManagerRecoveryHostManagerTest extends TestCase
{
    private const BUNDLE_ENTITIES = ['Nowo\\AuthKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    public function testClosedHostManagerIsNotResetWhenTheBundleManagerFails(): void
    {
        $registry = new SqliteManagerRegistry([
            'default' => ['App\\Entity' => dirname(__DIR__, 2) . '/Stub'],
            'auth'    => self::BUNDLE_ENTITIES,
        ]);
        $host = $this->manager($registry, 'default');
        $host->close();
        $bundle = $this->manager($registry, 'auth');

        $this->failWrite(new EntityManagerRecovery($registry), $bundle);

        self::assertSame($host, $registry->getManager('default'), 'The host manager must not be reset by the bundle.');
        self::assertFalse($this->manager($registry, 'default')->isOpen());
        self::assertNotSame($bundle, $registry->getManager('auth'));
        self::assertTrue($this->manager($registry, 'auth')->isOpen());
    }

    public function testConfiguredEntityManagerIsTheOnlyBundleManagerReset(): void
    {
        $registry = new SqliteManagerRegistry([
            'default' => self::BUNDLE_ENTITIES,
            'auth'    => self::BUNDLE_ENTITIES,
        ]);
        $host = $this->manager($registry, 'default');
        $host->close();
        $bundle = $this->manager($registry, 'auth');

        $this->failWrite(new EntityManagerRecovery($registry, 'auth'), $bundle);

        self::assertSame($host, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'auth')->isOpen());
    }

    public function testDefaultOnlyHostStillGetsItsManagerBack(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE_ENTITIES]);
        $manager  = $this->manager($registry, 'default');

        $this->failWrite(new EntityManagerRecovery($registry), $manager);

        self::assertNotSame($manager, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'default')->isOpen());
    }

    public function testManagerTheFailedFlushWentThroughIsReset(): void
    {
        $registry = new SqliteManagerRegistry([
            'default' => ['App\\Entity' => dirname(__DIR__, 2) . '/Stub'],
            'other'   => ['App\\Other' => dirname(__DIR__, 2) . '/Stub'],
            'auth'    => self::BUNDLE_ENTITIES,
        ]);
        $users = $this->manager($registry, 'default');
        $other = $this->manager($registry, 'other');
        $other->close();
        $users->close();

        try {
            (new EntityManagerRecovery($registry))->flush($users);
            self::fail('A flush on a closed manager must fail.');
        } catch (Throwable) {
        }

        self::assertTrue($this->manager($registry, 'default')->isOpen());
        self::assertSame($other, $registry->getManager('other'));
    }

    private function failWrite(EntityManagerRecovery $recovery, EntityManagerInterface $bundle): void
    {
        try {
            $recovery->run(static function () use ($bundle): void {
                $bundle->close();
                if (!$bundle->isOpen()) {
                    throw new RuntimeException('flush failed');
                }
            });
            self::fail('The write exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('flush failed', $exception->getMessage());
        }
    }

    private function manager(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
