<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaPasswordChangeListener;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\EventDispatcher\Event;
use WeakReference;

/**
 * REQ-RUNTIME-002: the memo of users with a changed password is emptied at every request / console / Messenger
 * boundary and by reset(), never only by kernel.reset.
 */
final class MfaPasswordChangeListenerTest extends TestCase
{
    public function testResetEmptiesTheMemo(): void
    {
        $listener = $this->listener();
        $this->fill($listener);

        $listener->reset();

        self::assertSame([], $this->memo($listener));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function boundaries(): iterable
    {
        yield 'kernel.terminate' => ['kernel.terminate'];
        yield 'console.terminate' => ['console.terminate'];
        yield 'Messenger received' => ['Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent'];
        yield 'Messenger handled' => ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'];
        yield 'Messenger failed' => ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'];
    }

    #[DataProvider('boundaries')]
    public function testEveryBoundaryEmptiesTheMemo(string $event): void
    {
        $listener   = $this->listener();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $this->fill($listener);

        $dispatcher->dispatch(new Event(), $event);

        self::assertSame([], $this->memo($listener));
    }

    public function testTheMainRequestEmptiesTheMemoAndASubRequestDoesNot(): void
    {
        $listener   = $this->listener();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $this->fill($listener);

        $dispatcher->dispatch(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST), 'kernel.request');
        self::assertCount(1, $this->memo($listener), 'A sub-request runs inside the request that owns the flush.');

        $dispatcher->dispatch(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        self::assertSame([], $this->memo($listener));
    }

    private function listener(): MfaPasswordChangeListener
    {
        return new MfaPasswordChangeListener(new MfaManager(
            ProfileRegistryFactory::single(TestUser::class),
            new MfaStore($this->createStub(ManagerRegistry::class)),
            new MfaOwnerResolver(),
            new MockClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        ));
    }

    private function fill(MfaPasswordChangeListener $listener): void
    {
        $user = new TestUser('alice@example.com');
        (new ReflectionProperty(MfaPasswordChangeListener::class, 'changed'))->setValue($listener, [1 => ['uow' => WeakReference::create($user), 'users' => [2 => $user]]]);
    }

    /**
     * @return array<mixed>
     */
    private function memo(MfaPasswordChangeListener $listener): array
    {
        $memo = (new ReflectionProperty(MfaPasswordChangeListener::class, 'changed'))->getValue($listener);
        self::assertIsArray($memo);

        return $memo;
    }
}
