<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\Proxy;

/**
 * A registry over one entity manager that maps every class (no PHPUnit stub: stubs record their invocations, which
 * would hide a memory leak in the worker-mode tests).
 */
final class SingleManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct('ORM', [], ['default' => 'default'], '', 'default', Proxy::class);
    }

    public function getManagerForClass(string $class): ObjectManager
    {
        return $this->em;
    }

    protected function getService(string $name): EntityManagerInterface
    {
        return $this->em; // @codeCoverageIgnore
    }

    protected function resetService(string $name): void
    {
    }
}
