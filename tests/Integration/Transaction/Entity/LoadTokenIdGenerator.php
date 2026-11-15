<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

use function bin2hex;
use function random_bytes;

/**
 * A pre-insert id generator (like a sequence or a UUID generator).
 */
final class LoadTokenIdGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, ?object $entity): string
    {
        return 'gen-' . bin2hex(random_bytes(6));
    }
}
