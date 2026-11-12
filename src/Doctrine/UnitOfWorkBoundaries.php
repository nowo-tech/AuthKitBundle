<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use WeakMap;

use function array_splice;
use function count;
use function max;

use const PHP_INT_MAX;

/**
 * Tracks which unit of work is running in this process, so the bundle acts only at the OUTERMOST boundary of a
 * request, a Messenger message or a console command (1.46.0, copied from PageBuilderKitBundle 1.15.1; REQ-RUNTIME-002
 * rule 4). Used by {@see PrivateEntityManagerSubscriber} (drops the bundle's private entity manager).
 *
 * The state changes at priority `PHP_INT_MAX`, before any other listener can throw:
 * - console: a stack of frames, one per running command, keyed by the Command AND its input (a command that runs
 *   itself in-process gets two frames). A frame remembers whether its command started as the outermost unit (no
 *   other frame, no message, no main request) and saves the message / request state of the moment it was pushed;
 *   removing the frame restores that state, so a message or request whose closing event never came (a throwing
 *   `WorkerMessageReceivedEvent` or `kernel.exception` listener inside the command) does not outlive the command;
 * - `console.terminate` removes ITS frame (and the frames above it, nested commands that never terminated); a
 *   terminate without a matching frame never removes another command's frame;
 * - `console.error` marks the frame as failing until its own terminate. When an error listener throws, Symfony
 *   never dispatches that terminate: the marked frame (and those above it) is dropped at the next request, message,
 *   command or worker stop (self-healing, so the process never stays "inside a command" for good);
 * - main requests in flight are counted (a main request handled in-process inside another one does not end it
 *   early); a top-level `kernel.terminate` (no command, no message) resets the count, so a request whose
 *   `kernel.finish_request` never came does not stay in flight;
 * - a Messenger message in flight: received → handled / failed / worker stopped. `WorkerMessageSkipEvent` (dispatched
 *   only by `messenger:failed:retry` when a message is skipped) also ends it.
 *
 * Rule for every handler: failed frames are dropped FIRST (which restores the state saved with them), THEN the
 * handler applies its own change, so a restored flag never overrides the event being handled.
 *
 * The verdict for each console event ("was this the outermost unit?") is kept per event object (`WeakMap`), so the
 * late listeners that act on it read the value computed when the event started, whatever ran in between.
 *
 * Process-lifetime state, deliberately NOT reset by `kernel.reset`: it describes the units still running.
 *
 * @internal
 */
final class UnitOfWorkBoundaries implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';
    public const MESSENGER_WORKER_MESSAGE_FAILED   = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';
    public const MESSENGER_WORKER_MESSAGE_SKIPPED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent';
    public const MESSENGER_WORKER_STOPPED          = 'Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent';

    /** Console event names (ConsoleEvents::COMMAND / ERROR / TERMINATE). */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_ERROR     = 'console.error';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /** @var list<array{command: object, input: object|null, outermost: bool, failing: bool, message: bool, requests: int}> running commands, outermost first */
    private array $frames = [];

    private bool $messageInFlight = false;

    /** Main requests in flight (main `kernel.request` → `kernel.finish_request`). */
    private int $requests = 0;

    /** @var WeakMap<object, bool> outermost verdict per console event */
    private WeakMap $verdicts;

    public function __construct()
    {
        $this->verdicts = new WeakMap();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => ['onKernelTerminate', PHP_INT_MAX],
            self::CONSOLE_COMMAND                   => ['onCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => ['onTerminate', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageFinished', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', PHP_INT_MAX],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->dropFailedFrames();
        // @igor-ignore - Count of the main requests in flight (no request data).
        ++$this->requests;
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dropFailedFrames();
            // @igor-ignore - Count of the main requests in flight (no request data).
            $this->requests = max(0, $this->requests - 1);
        }
    }

    /**
     * End of a top-level request (no command, no message): no main request can still be in flight.
     */
    public function onKernelTerminate(): void
    {
        $this->dropFailedFrames();
        if ($this->isHttpOutermost()) {
            // @igor-ignore - Count of the main requests in flight (no request data).
            $this->requests = 0;
        }
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->dropFailedFrames();
        $outermost = $this->isIdle();
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $outermost;
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames[] = [
            'command'   => $event->getCommand() ?? $event,
            'input'     => $event->getInput(),
            'outermost' => $outermost,
            'failing'   => false,
            'message'   => $this->messageInFlight,
            'requests'  => $this->requests,
        ];
    }

    /**
     * A command failed (or was not found: no command, no frame). Its verdict is its frame's, and the frame stays
     * marked until its own terminate; without a frame it is the outermost unit only when nothing else runs.
     */
    public function onError(ConsoleErrorEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
            $this->verdicts[$event] = $this->isIdle();

            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        // @igor-ignore - Failure marker of a running command, removed with its frame.
        $this->frames[$index]['failing'] = true;
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            $this->dropFailedFrames();
            // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
            $this->verdicts[$event] = $this->isIdle();

            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        // Its own frame and the nested commands above it that never terminated.
        $this->removeFrames($index);
    }

    public function onMessageReceived(): void
    {
        $this->dropFailedFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
    }

    public function onMessageFinished(): void
    {
        $this->dropFailedFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    public function onWorkerStopped(): void
    {
        $this->dropFailedFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /**
     * Whether the console event started or ended the outermost unit of work. An event this tracker did not see is
     * judged on the current state.
     */
    public function isOutermost(object $consoleEvent): bool
    {
        return $this->verdicts[$consoleEvent] ?? $this->isIdle();
    }

    /**
     * A main request is the outermost unit unless a command or a message runs it in-process.
     */
    public function isHttpOutermost(): bool
    {
        return $this->frames === [] && !$this->messageInFlight;
    }

    public function runningCommands(): int
    {
        return count($this->frames);
    }

    private function isIdle(): bool
    {
        return $this->frames === [] && !$this->messageInFlight && $this->requests === 0;
    }

    private function indexOf(?object $command, object $input): ?int
    {
        if ($command === null) {
            return null;
        }

        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command && $this->frames[$i]['input'] === $input) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Removes the frame at $index and every frame above it, restoring the message / request state saved when that
     * frame was pushed.
     */
    private function removeFrames(int $index): void
    {
        // @igor-ignore - Unit-of-work flag restored with the command frame.
        $this->messageInFlight = $this->frames[$index]['message'];
        // @igor-ignore - Count of the main requests in flight, restored with the command frame.
        $this->requests = $this->frames[$index]['requests'];
        // @igor-ignore - Stack of the running commands (no request data).
        array_splice($this->frames, $index);
    }

    /**
     * Drops the commands whose error listeners threw (Symfony never dispatches their terminate), with the nested
     * frames above them.
     */
    private function dropFailedFrames(): void
    {
        foreach ($this->frames as $i => $frame) {
            if ($frame['failing']) {
                $this->removeFrames($i);

                return;
            }
        }
    }
}
