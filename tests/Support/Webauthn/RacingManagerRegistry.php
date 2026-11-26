<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Webauthn;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\Proxy;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;

/**
 * A registry over one entity manager that runs a peer's write (once) just before its `$beforeCall`-th manager lookup
 * (2.5.0): a {@see WebauthnStore} looks its manager up twice per statement, so the 3rd
 * lookup is the first write after a read. It lets a single-connection (SQLite) test put another writer between the
 * read and the conditional UPDATE of a passkey rename.
 */
final class RacingManagerRegistry extends AbstractManagerRegistry
{
    private int $calls = 0;

    /**
     * @param Closure(EntityManagerInterface): void $peer
     */
    public function __construct(private readonly EntityManagerInterface $em, private readonly int $beforeCall, private readonly Closure $peer)
    {
        parent::__construct('ORM', [], ['default' => 'default'], '', 'default', Proxy::class);
    }

    public function getManagerForClass(string $class): ObjectManager
    {
        if (++$this->calls === $this->beforeCall) {
            ($this->peer)($this->em);
        }

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
