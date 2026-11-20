<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 1.54.0, REQ-RUNTIME-002 rule 4 / FrankenPHP worker mode with kernel reset off: one booted kernel serves every request
 * through the `http_kernel` service, so `kernel.reset` never runs. The host-flush probe (host mode, the default
 * `doctrine.private_entity_manager`) reports a foreign class once per request, again in the next request (nothing
 * reported survives the boundary), and no unit-of-work depth leaks from one request to the next.
 */
final class HostFlushProbeWorkerKernelTest extends MfaKernelTestCase
{
    /** @var list<string> */
    private array $reports = [];

    /**
     * A production-like kernel (debug off), as in a worker.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false]);
    }

    public function testEachRequestReportsAgainAndNothingSurvivesTheBoundary(): void
    {
        $http     = self::getContainer()->get('http_kernel');
        $qr       = self::getContainer()->get(QrLoginChallengeManager::class);
        $reporter = self::getContainer()->get(HostFlushReporter::class);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        self::assertInstanceOf(HostFlushReporter::class, $reporter);

        // Test only: restored in the finally block below.
        set_error_handler(function (int $level, string $message): bool { // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
            if (str_contains($message, 'were flushed by NowoAuthKitBundle')) {
                $this->reports[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED);

        try {
            for ($request = 0; $request < 3; ++$request) {
                $this->serve($http, function () use ($qr, $request): void {
                    // Two AuthKit writes in the same request, each with a pending application change: one report.
                    foreach (['a', 'b'] as $suffix) {
                        $this->em()->persist((new KernelUser())->setEmail("pending-{$request}{$suffix}@example.com")->setPassword('pending-password'));
                        $qr->create(Request::create('/login/qr'));
                    }
                });
                self::assertCount($request + 1, $this->reports, 'one report per request, again after every boundary');
                self::assertTrue($reporter->enter(), 'no unit-of-work depth leaks out of the request');
                $reporter->leave();
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame('Your pending changes to ' . KernelUser::class . ' were flushed by NowoAuthKitBundle; 2.0 no longer does.', $this->reports[0]);
        // Behaviour unchanged in 1.x: host mode still writes the application's pending rows.
        self::assertSame(6, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email LIKE 'pending-%'"));
    }

    /**
     * One worker request: kernel.request (boundary), the work, then kernel.terminate (boundary), as FrankenPHP does
     * without resetting the kernel.
     *
     * @param callable(): void $work
     */
    private function serve(HttpKernelInterface $http, callable $work): void
    {
        $request  = Request::create('/login');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $work();
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
