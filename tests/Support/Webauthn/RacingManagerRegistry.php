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
 * A registry over one entity manager that runs a peer's write just before its `$beforeCall`-th manager lookup, and
 * then every `$every` lookups when `$every` > 0 (2.5.0): a {@see WebauthnStore} looks its manager up twice per
 * statement, so the 3rd lookup is the first write after a read and every 4th after it the next write. It lets a
 * single-connection (SQLite) test put another writer between the reads and the conditional UPDATEs of a passkey rename.
 */
final class RacingManagerRegistry extends AbstractManagerRegistry
{
    private int $calls = 0;

    /**
     * @param Closure(EntityManagerInterface): void $peer
     */
    public function __construct(private readonly EntityManagerInterface $em, private readonly int $beforeCall, private readonly Closure $peer, private readonly int $every = 0)
    {
        parent::__construct('ORM', [], ['default' => 'default'], '', 'default', Proxy::class);
    }

    public function getManagerForClass(string $class): ObjectManager
    {
        $call = ++$this->calls - $this->beforeCall;
        if ($call === 0 || ($this->every > 0 && $call > 0 && $call % $this->every === 0)) {
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
