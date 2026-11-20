<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\SchemaFk;

use Doctrine\ORM\Mapping as ORM;

/** Audit XI: the target of {@see XiChild}'s foreign key. */
#[ORM\Entity]
#[ORM\Table(name: 'xi_parent')]
class XiParent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
