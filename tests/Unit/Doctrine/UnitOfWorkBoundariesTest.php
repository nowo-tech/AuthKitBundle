<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Nowo\AuthKitBundle\Doctrine\UnitOfWorkBoundaries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelEvents;

use const PHP_INT_MAX;

/**
 * 1.15.1 (NL2): the unit-of-work tracker changes state before any other listener can throw, remembers each command's
 * verdict, never lets a stray terminate pop another command, and heals after a failed error listener.
 */
#[CoversClass(UnitOfWorkBoundaries::class)]
final class UnitOfWorkBoundariesTest extends TestCase
{
    #[Test]
    public function changesStateAtTheExtremePriorities(): void
    {
        $events = UnitOfWorkBoundaries::getSubscribedEvents();

        self::assertSame(['onRequest', PHP_INT_MAX], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', PHP_INT_MAX], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame(['onKernelTerminate', PHP_INT_MAX], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onCommand', PHP_INT_MAX], $events[UnitOfWorkBoundaries::CONSOLE_COMMAND]);
        self::assertSame(['onError', PHP_INT_MAX], $events[UnitOfWorkBoundaries::CONSOLE_ERROR]);
        self::assertSame(['onTerminate', PHP_INT_MAX], $events[UnitOfWorkBoundaries::CONSOLE_TERMINATE]);
        self::assertSame(['onMessageFinished', PHP_INT_MAX], $events[UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_SKIPPED]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[UnitOfWorkBoundaries::MESSENGER_WORKER_STOPPED]);
        self::assertCount(11, $events);
    }

    #[Test]
    public function aThrowingTerminateListenerStillEndsTheCommand(): void
    {
        $events  = new BoundaryEvents(new UnitOfWorkBoundaries());
        $command = new Command('app:x');
        $events->dispatcher->addListener(UnitOfWorkBoundaries::CONSOLE_TERMINATE, static function (): never {
            throw new RuntimeException('terminate listener failed');
        });

        $events->command($command);
        try {
            $events->terminate($command);
            self::fail('the terminate listener throws');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $events->boundaries->runningCommands());
        self::assertTrue($events->boundaries->isHttpOutermost());
    }

    #[Test]
    public function anOuterTerminateDropsNestedCommandsThatNeverTerminated(): void
    {
        $events = new BoundaryEvents(new UnitOfWorkBoundaries());
        $outer  = new Command('app:outer');
        $input  = new ArrayInput([]);

        $events->command($outer, $input);
        $events->command(new Command('app:lost-1'));
        $events->command(new Command('app:lost-2'));
        self::assertSame(3, $events->boundaries->runningCommands());

        $terminate = new ConsoleTerminateEvent($outer, $input, new NullOutput(), 0);
        $events->dispatcher->dispatch($terminate, UnitOfWorkBoundaries::CONSOLE_TERMINATE);
        self::assertSame(0, $events->boundaries->runningCommands());
        self::assertTrue($events->boundaries->isOutermost($terminate));
    }

    #[Test]
    public function aFailedErrorListenerHealsAtTheNextUnitOnly(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);
        $outer      = new Command('app:outer');
        $failing    = new Command('app:failing');

        // A handled error: the frame stays marked until its own terminate, which removes it.
        $events->command($outer);
        $events->error($outer);
        $events->error(new Command('app:unknown-to-the-tracker'));
        self::assertSame(1, $boundaries->runningCommands());
        $events->terminate($outer);
        self::assertSame(0, $boundaries->runningCommands());

        // The nested command's error listener throws: no terminate; the next unit heals, the outer frame stays.
        $events->dispatcher->addListener(UnitOfWorkBoundaries::CONSOLE_ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });
        $events->command($outer);
        $events->command($failing);
        try {
            $events->error($failing);
        } catch (RuntimeException) {
        }
        self::assertSame(2, $boundaries->runningCommands());
        $events->command(new Command('app:next'));
        self::assertSame(2, $boundaries->runningCommands(), 'failing frame dropped, the new one pushed');

        // The same at a stray terminate, a message and when the worker stops.
        foreach ([
            static fn () => $events->terminate(new Command('app:stray')),
            static fn () => $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED),
            static fn () => $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_STOPPED),
        ] as $nextUnit) {
            $events->command($failing);
            try {
                $events->error($failing);
            } catch (RuntimeException) {
            }
            self::assertSame(3, $boundaries->runningCommands());
            $nextUnit();
            self::assertSame(2, $boundaries->runningCommands());
            $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED);
        }
    }

    /**
     * NL2-v N1: a command that runs itself in-process has one frame per run (Command + input).
     */
    #[Test]
    public function aSelfRecursiveCommandWithAThrowingErrorListenerEndsWithItsOuterRun(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);
        $command    = new Command('app:recursive');
        $outerRun   = new ArrayInput([]);
        $innerRun   = new ArrayInput([]);
        $events->dispatcher->addListener(UnitOfWorkBoundaries::CONSOLE_ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });

        $events->command($command, $outerRun);
        $events->command($command, $innerRun);
        try {
            $events->error($command, $innerRun);
        } catch (RuntimeException) {
        }
        $terminate = new ConsoleTerminateEvent($command, $outerRun, new NullOutput(), 0);
        $events->dispatcher->dispatch($terminate, UnitOfWorkBoundaries::CONSOLE_TERMINATE);

        self::assertSame(0, $boundaries->runningCommands());
        self::assertTrue($boundaries->isOutermost($terminate));
    }

    /**
     * NL2-v N3: a frame restores the message / request state of its start, so a message or request whose closing
     * event never came inside the command does not outlive it; nested main requests are counted.
     */
    #[Test]
    public function aCommandFrameRestoresTheMessageAndRequestStateOfItsStart(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);
        $consume    = new Command('app:consume');

        $events->command($consume);
        $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $events->request();
        $events->terminate($consume);
        self::assertTrue($boundaries->isHttpOutermost(), 'no message left in flight');
        self::assertTrue($boundaries->isOutermost(new stdClass()), 'no request left in flight');

        // Nested main requests: the inner finish does not end the outer request.
        $events->request();
        $events->request();
        $events->finishRequest();
        self::assertFalse($boundaries->isOutermost(new stdClass()));
        $events->finishRequest();
        $events->finishRequest();
        self::assertTrue($boundaries->isOutermost(new stdClass()), 'never below zero');

        // A top-level request whose finish never came is over at its terminate; inside a command it is not.
        $events->request();
        $events->kernelTerminate();
        self::assertTrue($boundaries->isOutermost(new stdClass()));
        $events->command($consume);
        $events->request();
        $events->kernelTerminate();
        $start = new ConsoleCommandEvent(new Command('app:nested'), new ArrayInput([]), new NullOutput());
        $events->dispatcher->dispatch($start, UnitOfWorkBoundaries::CONSOLE_COMMAND);
        self::assertFalse($boundaries->isOutermost($start));
    }

    /**
     * NL2-v R1: every handler drops failed frames FIRST, so a restored flag never overrides the event being handled.
     */
    #[Test]
    public function droppedFramesNeverOverrideTheEventBeingHandled(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);
        $failing    = new Command('app:failing');
        $events->dispatcher->addListener(UnitOfWorkBoundaries::CONSOLE_ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });
        $failInside = static function (callable $open) use ($events, $failing): void {
            $open();
            $events->command($failing);
            try {
                $events->error($failing);
            } catch (RuntimeException) {
            }
        };

        foreach ([UnitOfWorkBoundaries::MESSENGER_WORKER_STOPPED, UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_FAILED] as $end) {
            $failInside(static fn () => $events->message(UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED));
            $events->message($end);
            self::assertTrue($boundaries->isHttpOutermost(), $end . ' ends the message');
            self::assertSame(0, $boundaries->runningCommands());
        }

        $failInside(static fn () => $events->request());
        $events->finishRequest();
        self::assertTrue($boundaries->isOutermost(new stdClass()), 'finish_request ends the request');

        $failInside(static fn () => $events->request());
        $events->kernelTerminate();
        self::assertTrue($boundaries->isOutermost(new stdClass()), 'a top-level terminate ends the request');
    }

    #[Test]
    public function verdictsAreKeptPerEventAndUnseenEventsUseTheCurrentState(): void
    {
        $boundaries = new UnitOfWorkBoundaries();
        $events     = new BoundaryEvents($boundaries);

        self::assertTrue($boundaries->isOutermost(new stdClass()));
        $start = new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput());
        $events->dispatcher->dispatch($start, UnitOfWorkBoundaries::CONSOLE_COMMAND);
        self::assertTrue($boundaries->isOutermost($start), 'the verdict of the start, whatever runs now');
        self::assertFalse($boundaries->isOutermost(new stdClass()));
        self::assertFalse($boundaries->isHttpOutermost());

        $events->request();
        $events->finishRequest();
        self::assertSame(1, $boundaries->runningCommands());
    }
}
