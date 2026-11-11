<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaPasswordChangeListener;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use ReflectionProperty;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;
use WeakReference;

use function bin2hex;
use function count;
use function gc_collect_cycles;
use function hash;
use function memory_get_usage;
use function random_bytes;
use function spl_object_id;
use function sprintf;

/**
 * REQ-RUNTIME-002 (FrankenPHP worker mode, kernel kept alive and NOT reset): one booted kernel serves every request
 * through the `http_kernel` service, so `kernel.reset` / the services resetter never runs. Two MFA users (alice =
 * member, bob = support) with trusted devices change their passwords in alternation; some flushes fail after
 * `onFlush` (a SQLite trigger aborts the UPDATE), so `postFlush` never runs for them.
 *
 * Before 1.45.1, `MfaPasswordChangeListener::$changed` kept the user of the failed flush and the next flush of any
 * other user forgot that user's trusted devices. Each password change must forget only its own user's devices.
 */
final class MfaPasswordChangeWorkerKernelTest extends MfaKernelTestCase
{
    private const ALICE = 'member@example.com';
    private const BOB   = 'support@example.com';

    /** Requests in the many-request test (each one: an HTTP request + a password flush + device rows). */
    private const ITERATIONS = 500;

    /** Allowed memory growth between the warm-up and the last iteration. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    /**
     * A production-like kernel (debug off): in debug mode the profiler, the Doctrine query logger and the Twig
     * profile keep every request until kernel.reset, which this worker test never runs.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false]);
    }

    public function testAFailedFlushNeverForgetsTheDevicesOfThatUserOnALaterFlush(): void
    {
        $this->enableWithDevice(self::ALICE);
        $this->enableWithDevice(self::BOB);
        $http = $this->http();
        $this->serve($http);

        // Request 1 (alice): the password flush throws after onFlush; postFlush never runs.
        $this->failingPasswordChange(self::ALICE);
        self::assertSame(1, $this->deviceCount(self::ALICE));

        // Request 2 (bob), same kernel, no reset: bob's password change forgets bob's devices only.
        $this->serve($http);
        self::assertSame([], $this->pending(), 'The main kernel.request empties the memo.');
        $this->changePassword(self::BOB, 'bob-password-new-1');
        self::assertSame(0, $this->deviceCount(self::BOB));
        self::assertSame(1, $this->deviceCount(self::ALICE), 'Alice\'s trusted devices survive bob\'s flush.');
    }

    public function testTheNextFlushOfTheSameManagerDropsTheUsersOfAFailedOneWithoutABoundary(): void
    {
        $this->enableWithDevice(self::ALICE);
        $this->enableWithDevice(self::BOB);

        // No request in between (e.g. one console command or one Messenger message doing both writes).
        $this->failingPasswordChange(self::ALICE);
        $this->changePassword(self::BOB, 'bob-password-new-2');
        self::assertSame(0, $this->deviceCount(self::BOB));
        self::assertSame(1, $this->deviceCount(self::ALICE));
    }

    public function testAFailedFlushClearsItsUsersAndResetEmptiesTheMemo(): void
    {
        $this->enableWithDevice(self::ALICE);
        $this->failingPasswordChange(self::ALICE);
        self::assertSame([], $this->pending(), 'The failed commit closes and clears the manager: onClear drops alice.');

        $listener = $this->listener();
        $alice    = $this->user(self::ALICE);
        $listener->onFlush(new OnFlushEventArgs($this->stubManager($this->unitOfWorkWith([$alice]))));
        self::assertCount(1, $this->pending());
        $listener->reset();
        self::assertSame([], $this->pending());
    }

    /**
     * MD-A-L1 (1.45.2): a nested flush of the same unit of work (e.g. from a lifecycle callback inside the outer commit)
     * keeps the outer flush's users; the outer postFlush still forgets the devices.
     */
    public function testANestedFlushOfTheSameUnitOfWorkKeepsTheOuterUsers(): void
    {
        $this->enableWithDevice(self::ALICE);
        $listener = $this->listener();
        $alice    = $this->user(self::ALICE);
        $outer    = $this->unitOfWorkWith([$alice]);
        $manager  = $this->stubManager($outer);

        $listener->onFlush(new OnFlushEventArgs($manager));
        // Nested flush: same unit of work, nothing new scheduled.
        $listener->onFlush(new OnFlushEventArgs($manager));
        self::assertCount(1, $this->pending(), 'The outer flush\'s user is kept.');

        $listener->postFlush(new PostFlushEventArgs($manager));
        self::assertSame(0, $this->deviceCount(self::ALICE), 'Alice\'s committed password change forgets her devices.');
        self::assertSame([], $this->pending());

        // A unit of work that is gone (its object id reused by a new one): its users are never carried over.
        $listener->onFlush(new OnFlushEventArgs($manager));
        $this->addDevice(self::ALICE);
        $other = $this->unitOfWorkWith([]);
        (new ReflectionProperty(MfaPasswordChangeListener::class, 'changed'))->setValue($listener, [
            spl_object_id($other) => ['uow' => WeakReference::create(new stdClass()), 'users' => [spl_object_id($alice) => $alice]],
        ]);
        $listener->postFlush(new PostFlushEventArgs($this->stubManager($other)));
        self::assertSame(1, $this->deviceCount(self::ALICE));
        (new ReflectionProperty(MfaPasswordChangeListener::class, 'changed'))->setValue($listener, [
            spl_object_id($other) => ['uow' => WeakReference::create(new stdClass()), 'users' => [spl_object_id($alice) => $alice]],
        ]);
        $listener->onFlush(new OnFlushEventArgs($this->stubManager($other)));
        self::assertSame([], $this->pending());
    }

    /**
     * @param list<object> $updates
     */
    private function unitOfWorkWith(array $updates): UnitOfWork
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturnOnConsecutiveCalls($updates, [], [], []);
        $unitOfWork->method('getEntityChangeSet')->willReturn(['password' => ['old', 'new']]);

        return $unitOfWork;
    }

    private function stubManager(UnitOfWork $unitOfWork): EntityManagerInterface
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $manager;
    }

    public function testManyRequestsAlternatingTwoUsersKeepDevicesAndMemoryApart(): void
    {
        $this->enableWithDevice(self::ALICE);
        $this->enableWithDevice(self::BOB);
        $http     = $this->http();
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            [$user, $other] = $i % 2 === 0 ? [self::ALICE, self::BOB] : [self::BOB, self::ALICE];
            $this->addDevice($user);
            $this->serve($http);
            if ($i % 10 === 0) {
                $this->failingPasswordChange($other);
                $this->serve($http);
            }
            $kept = $this->deviceCount($other);
            $this->changePassword($user, sprintf('password-%d-%s', $i, $user));

            self::assertSame(0, $this->deviceCount($user), sprintf('Iteration %d: %s forgets its own devices.', $i, $user));
            self::assertSame($kept, $this->deviceCount($other), sprintf('Iteration %d: %s keeps its devices.', $i, $other));
            self::assertSame([], $this->pending(), sprintf('Iteration %d: nothing stays in the memo.', $i));

            $this->events = [];
            if ($i === 49) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over the requests.');
    }

    private function http(): HttpKernelInterface
    {
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        return $http;
    }

    private function serve(HttpKernelInterface $http): void
    {
        $request  = Request::create('/login');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }

    private function listener(): MfaPasswordChangeListener
    {
        $listener = self::getContainer()->get(MfaPasswordChangeListener::class);
        self::assertInstanceOf(MfaPasswordChangeListener::class, $listener);

        return $listener;
    }

    /**
     * @return array<mixed>
     */
    private function pending(): array
    {
        $changed = (new ReflectionProperty(MfaPasswordChangeListener::class, 'changed'))->getValue($this->listener());
        self::assertIsArray($changed);

        return $changed;
    }

    private function store(): MfaStore
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new MfaStore($doctrine);
    }

    private function credentialOf(string $email): MfaCredential
    {
        $credential = $this->manager()->credential($this->user($email));
        self::assertInstanceOf(MfaCredential::class, $credential);

        return $credential;
    }

    private function enableWithDevice(string $email): void
    {
        $secret = $this->manager()->newSecret();
        self::assertNotNull($this->manager()->enable($this->user($email), $secret, $this->code($secret)));
        $this->nextStep();
        $this->addDevice($email);
    }

    private function addDevice(string $email): void
    {
        $now = $this->clock()->now();
        $this->store()->insertDevice($this->credentialOf($email), bin2hex(random_bytes(8)), hash('sha256', bin2hex(random_bytes(16))), 'Worker test', null, $now, $now->modify('+30 days'));
    }

    private function deviceCount(string $email): int
    {
        return count($this->store()->devices($this->credentialOf($email), $this->clock()->now()));
    }

    private function changePassword(string $email, string $password): void
    {
        $user = $this->user($email);
        $user->setPassword($password);
        $this->em()->flush();
        $this->em()->clear();
    }

    /**
     * Changes the password with a trigger that aborts the UPDATE: onFlush runs, the commit throws, postFlush never
     * runs and the entity manager is closed (then reopened, as the bundle / host would).
     */
    private function failingPasswordChange(string $email): void
    {
        $connection = $this->em()->getConnection();
        $table      = $this->em()->getClassMetadata(KernelUser::class)->getTableName();
        $connection->executeStatement(sprintf("CREATE TRIGGER worker_test_abort BEFORE UPDATE ON %s WHEN NEW.email = '%s' BEGIN SELECT RAISE(ABORT, 'worker test: forced flush failure'); END", $table, $email));
        $user = $this->user($email);
        $user->setPassword('never-stored-password');
        try {
            $this->em()->flush();
            self::fail('The flush must fail.');
        } catch (Throwable $e) {
            self::assertStringContainsString('forced flush failure', $e->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER worker_test_abort');
        }
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        if (!$this->em()->isOpen()) {
            $doctrine->resetManager();
        }
        $this->em()->clear();
    }
}
