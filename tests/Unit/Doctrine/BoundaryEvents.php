<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Nowo\AuthKitBundle\Doctrine\UnitOfWorkBoundaries;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use WeakMap;

/**
 * Dispatches real boundary events through a real dispatcher holding {@see UnitOfWorkBoundaries} and the subscriber
 * under test (1.15.1 unit tests).
 */
final readonly class BoundaryEvents
{
    public EventDispatcher $dispatcher;

    /** @var WeakMap<Command, ArrayInput> one input per command run, as Symfony passes the same input to its events */
    private WeakMap $inputs;

    public function __construct(
        public UnitOfWorkBoundaries $boundaries,
        EventSubscriberInterface ...$subscribers,
    ) {
        $this->dispatcher = new EventDispatcher();
        $this->inputs     = new WeakMap();
        $this->dispatcher->addSubscriber($this->boundaries);
        foreach ($subscribers as $subscriber) {
            $this->dispatcher->addSubscriber($subscriber);
        }
    }

    /**
     * Starts a run of $command; $input distinguishes a command that runs itself in-process (default: one per command).
     */
    public function command(Command $command, ?ArrayInput $input = null): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent($command, $input ?? $this->input($command), new NullOutput()), UnitOfWorkBoundaries::CONSOLE_COMMAND);
    }

    public function error(?Command $command, ?ArrayInput $input = null): void
    {
        $input ??= $command instanceof Command ? $this->input($command) : new ArrayInput([]);
        $this->dispatcher->dispatch(new ConsoleErrorEvent($input, new NullOutput(), new RuntimeException('failed'), $command), UnitOfWorkBoundaries::CONSOLE_ERROR);
    }

    public function terminate(Command $command, ?ArrayInput $input = null): void
    {
        $this->dispatcher->dispatch(new ConsoleTerminateEvent($command, $input ?? $this->input($command), new NullOutput(), 0), UnitOfWorkBoundaries::CONSOLE_TERMINATE);
    }

    private function input(Command $command): ArrayInput
    {
        return $this->inputs[$command] ??= new ArrayInput([]);
    }

    public function request(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new RequestEvent(new StubHttpKernel(), new Request(), $type), KernelEvents::REQUEST);
    }

    public function finishRequest(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new FinishRequestEvent(new StubHttpKernel(), new Request(), $type), KernelEvents::FINISH_REQUEST);
    }

    public function exception(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new ExceptionEvent(new StubHttpKernel(), new Request(), $type, new RuntimeException('failed')), KernelEvents::EXCEPTION);
    }

    public function kernelTerminate(): void
    {
        $this->dispatcher->dispatch(new TerminateEvent(new StubHttpKernel(), new Request(), new Response()), KernelEvents::TERMINATE);
    }

    /**
     * A full main request: request, finish, terminate.
     */
    public function mainRequest(): void
    {
        $this->request();
        $this->finishRequest();
        $this->kernelTerminate();
    }

    public function message(string $eventName): void
    {
        $this->dispatcher->dispatch(new stdClass(), $eventName);
    }
}
