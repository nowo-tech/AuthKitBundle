<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Ddd;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The host leaves a change pending in its own unit of work at the start of every main request (persisted, never
 * flushed): whatever AuthKit writes during the request must not commit it.
 */
final class PendingHostChangeListener
{
    public ?KernelUser $last = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->last = (new KernelUser())->setEmail('pending@host.test')->setPassword('x');
        $this->entityManager->persist($this->last);
    }
}
