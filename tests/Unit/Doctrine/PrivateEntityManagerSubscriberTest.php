<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Doctrine\UnitOfWorkBoundaries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * REQ-RUNTIME-002 rule 4 (1.46.0, adapted from PageBuilderKitBundle 1.15.1): AuthKit's private entity manager is
 * dropped only at the OUTERMOST unit-of-work boundary,
 * through a real dispatcher with {@see UnitOfWorkBoundaries}.
 */
#[CoversClass(PrivateEntityManagerSubscriber::class)]
final class PrivateEntityManagerSubscriberTest extends TestCase
{
    private int $clears = 0;

    #[Test]
    public function subscribesToEveryUnitOfWorkBoundary(): void
    {
        $events = PrivateEntityManagerSubscriber::getSubscribedEvents();

        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onTerminate', -1024], $events[KernelEvents::TERMINATE]);
        self::assertSame(['clearState', 4096], $events[UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED]);
        self::assertSame(['clearState', -1024], $events[UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED]);
        self::assertSame(['clearState', -1024], $events[UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_FAILED]);
        self::assertSame(['onConsoleBoundary', 4096], $events[UnitOfWorkBoundaries::CONSOLE_COMMAND]);
        self::assertSame(['onConsoleBoundary', -1024], $events[UnitOfWorkBoundaries::CONSOLE_TERMINATE]);
    }

    #[Test]
    public function anUninitializedManagerIsNeverTouchedAndAWiredOneIsReset(): void
    {
        (new PrivateEntityManagerSubscriber(new UnitOfWorkBoundaries()))->clearState();

        $manager = $this->createMock(ResetInterface::class);
        $manager->expects(self::once())->method('reset');
        (new PrivateEntityManagerSubscriber(new UnitOfWorkBoundaries(), [$manager]))->clearState();
    }

    #[Test]
    public function clearsAtTheMainRequestStartAndAtTerminateOnly(): void
    {
        $events = $this->events();

        $events->request(HttpKernelInterface::SUB_REQUEST);
        self::assertSame(0, $this->clears);
        $events->request();
        self::assertSame(1, $this->clears);
        $events->finishRequest(HttpKernelInterface::SUB_REQUEST);
        $events->finishRequest();
        $events->kernelTerminate();
        self::assertSame(2, $this->clears);
    }

    #[Test]
    public function aNestedCommandDoesNotClearTheOuterCommand(): void
    {
        $events = $this->events();
        $outer  = new Command('app:outer');
        $nested = new Command('app:nested');

        $events->command($outer);
        self::assertSame(1, $this->clears);
        $events->command($nested);
        $events->terminate($nested);
        self::assertSame(1, $this->clears, 'a nested command must not clear the outer command');
        $events->terminate($outer);
        self::assertSame(2, $this->clears);

        // A stray terminate (no matching command) acts only when nothing runs, and never pops another command.
        $events->command($outer);
        self::assertSame(3, $this->clears);
        $events->terminate(new Command('app:stray'));
        self::assertSame(3, $this->clears);
        $events->terminate($outer);
        self::assertSame(4, $this->clears);
        $events->terminate(new Command('app:stray'));
        self::assertSame(5, $this->clears);
    }

    #[Test]
    public function aCommandInsideAHandlerDoesNotClearTheMessage(): void
    {
        $events  = $this->events();
        $command = new Command('app:from-handler');

        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        self::assertSame(1, $this->clears);
        $events->command($command);
        $events->terminate($command);
        self::assertSame(1, $this->clears, 'a command run from a handler must not clear the message');
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        self::assertSame(2, $this->clears);

        // Skipped message, then a stopped worker: no message in flight any more.
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_SKIPPED);
        self::assertSame(3, $this->clears);
        $events->command($command);
        self::assertSame(4, $this->clears, 'a skipped message is no longer in flight');
        $events->terminate($command);
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_STOPPED);
        self::assertSame(6, $this->clears);
        $events->command($command);
        self::assertSame(7, $this->clears, 'a stopped worker has no message in flight');
    }

    #[Test]
    public function aHandlerInsideACommandClearsPerMessageButNotTheCommand(): void
    {
        $events  = $this->events();
        $consume = new Command('messenger:consume');
        $nested  = new Command('app:nested');

        $events->command($consume);
        self::assertSame(1, $this->clears);
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        self::assertSame(2, $this->clears);
        $events->command($nested);
        $events->terminate($nested);
        self::assertSame(2, $this->clears, 'a command run from the handler must not clear the message');
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_FAILED);
        self::assertSame(3, $this->clears);
        $events->terminate($consume);
        self::assertSame(4, $this->clears);
    }

    #[Test]
    public function aRequestInsideACommandOrAMessageDoesNotClearIt(): void
    {
        $events  = $this->events();
        $command = new Command('app:crawler');

        $events->command($command);
        self::assertSame(1, $this->clears);
        $events->mainRequest();
        self::assertSame(1, $this->clears, 'a request handled inside a command must not clear it');
        $events->terminate($command);
        self::assertSame(2, $this->clears);

        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        self::assertSame(3, $this->clears);
        $events->mainRequest();
        self::assertSame(3, $this->clears, 'a request handled inside a message must not clear it');
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        self::assertSame(4, $this->clears);
    }

    #[Test]
    public function aCommandInsideAMainRequestDoesNotClearTheRequest(): void
    {
        $events  = $this->events();
        $command = new Command('app:from-controller');

        $events->request();
        self::assertSame(1, $this->clears);
        $events->command($command);
        $events->terminate($command);
        self::assertSame(1, $this->clears, 'a command run from a controller must not clear the request');
        $events->finishRequest(HttpKernelInterface::SUB_REQUEST);
        $events->command($command);
        $events->terminate($command);
        self::assertSame(1, $this->clears);
        $events->finishRequest();
        $events->kernelTerminate();
        self::assertSame(2, $this->clears);
    }

    /**
     * NL2-1: a command whose error listeners threw never terminates; the process heals at the next unit.
     */
    #[Test]
    public function aCommandWhoseErrorListenerThrewDoesNotBlockLaterRequests(): void
    {
        $events  = $this->events();
        $command = new Command('app:failing');
        $events->dispatcher->addListener(UnitOfWorkBoundaries::CONSOLE_ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });

        $events->command($command);
        try {
            $events->error($command);
            self::fail('the error listener throws');
        } catch (RuntimeException) {
        }
        self::assertSame(1, $events->boundaries->runningCommands(), 'no terminate was dispatched');

        self::assertSame(1, $this->clears);
        $events->mainRequest();
        self::assertSame(3, $this->clears, 'the next request is outermost again');
        self::assertSame(0, $events->boundaries->runningCommands());
    }

    private function events(): BoundaryEvents
    {
        $this->clears = 0;
        $manager      = $this->createStub(ResetInterface::class);
        $manager->method('reset')->willReturnCallback(function (): void {
            ++$this->clears;
        });

        $boundaries = new UnitOfWorkBoundaries();

        return new BoundaryEvents($boundaries, new PrivateEntityManagerSubscriber($boundaries, [$manager]));
    }
}
