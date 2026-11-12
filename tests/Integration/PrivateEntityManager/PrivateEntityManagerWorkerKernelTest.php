<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function bin2hex;
use function count;
use function gc_collect_cycles;
use function hash;
use function memory_get_usage;
use function random_bytes;
use function sprintf;

/**
 * REQ-RUNTIME-002 rule 4 with `doctrine.private_entity_manager: true` (1.46.0): one booted kernel serves every request
 * through the `http_kernel` service, so `kernel.reset` / the services resetter never runs. Two MFA users (alice =
 * member, bob = support) alternate: each iteration serves a request, reads the user's credential, adds a trusted
 * device and creates a QR login challenge through the private manager.
 *
 * Each read returns only that user's rows, the private manager is dropped at every request boundary (no AuthKit row
 * survives into the next request), and memory stays flat.
 */
final class PrivateEntityManagerWorkerKernelTest extends MfaKernelTestCase
{
    private const ALICE = 'member@example.com';
    private const BOB   = 'support@example.com';

    /** Requests in the many-request test (each: an HTTP request, a credential read, a device row, a QR challenge). */
    private const ITERATIONS = 600;

    /** Allowed memory growth between the warm-up (iteration 50) and the last iteration. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    protected static function getKernelClass(): string
    {
        return PrivateEmMfaTestKernel::class;
    }

    /**
     * A production-like kernel (debug off): in debug mode the profiler and the Doctrine query logger keep every request
     * until kernel.reset, which this worker test never runs.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false]);
    }

    public function testManyRequestsAlternatingTwoUsersKeepRowsAndMemoryApart(): void
    {
        $this->enable(self::ALICE);
        $this->enable(self::BOB);
        $http    = $this->http();
        $private = $this->privateManager();
        $qr      = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        $devices  = [self::ALICE => 0, self::BOB => 0];
        $baseline = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            [$user, $other] = $i % 2 === 0 ? [self::ALICE, self::BOB] : [self::BOB, self::ALICE];
            $this->serve($http);
            self::assertFalse($private->isCreated(), sprintf('Iteration %d: the request boundary dropped the private manager.', $i));

            $credential = $this->credentialOf($user);
            self::assertSame($user, $credential->getUserId(), sprintf('Iteration %d: %s reads its own credential.', $i, $user));
            $this->addDevice($credential);
            ++$devices[$user];
            self::assertSame($devices[$user], $this->deviceCount($user), sprintf('Iteration %d: %s sees its own devices.', $i, $user));
            self::assertSame($devices[$other], $this->deviceCount($other), sprintf('Iteration %d: %s is untouched.', $i, $other));

            $challenge = $qr->create(Request::create('/login/qr'))['challenge'];
            $found     = $qr->find($challenge->getId());
            self::assertInstanceOf(QrLoginChallenge::class, $found);
            self::assertSame(QrLoginChallengeStatus::Pending, $found->getStatus());
            self::assertLessThanOrEqual(2, count($private->live()->getUnitOfWork()->getIdentityMap()[MfaCredential::class] ?? []), 'Only this request\'s credentials are managed.');

            $this->events = [];
            if ($i === 49) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        $this->serve($http);
        self::assertFalse($private->isCreated());
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over the requests.');
    }

    private function enable(string $email): void
    {
        $secret = $this->manager()->newSecret();
        self::assertNotNull($this->manager()->enable($this->user($email), $secret, $this->code($secret)));
        $this->nextStep();
    }

    private function credentialOf(string $email): MfaCredential
    {
        $credential = $this->manager()->credential($this->user($email));
        self::assertInstanceOf(MfaCredential::class, $credential);

        return $credential;
    }

    private function addDevice(MfaCredential $credential): void
    {
        $now = $this->clock()->now();
        $this->store()->insertDevice($credential, bin2hex(random_bytes(8)), hash('sha256', bin2hex(random_bytes(16))), 'Worker test', null, $now, $now->modify('+30 days'));
    }

    private function deviceCount(string $email): int
    {
        return count($this->store()->devices($this->credentialOf($email), $this->clock()->now()));
    }

    private function store(): MfaStore
    {
        $store = self::getContainer()->get(MfaStore::class);
        self::assertInstanceOf(MfaStore::class, $store);

        return $store;
    }

    private function privateManager(): AuthKitEntityManager
    {
        $private = self::getContainer()->get(AuthKitEntityManager::class);
        self::assertInstanceOf(AuthKitEntityManager::class, $private);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $private;
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
}
