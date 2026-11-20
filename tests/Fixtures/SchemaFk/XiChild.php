<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\SchemaFk;

use Doctrine\ORM\Mapping as ORM;

/** Audit XI: a table with a foreign key, an index and an identity column to collate. */
#[ORM\Entity]
#[ORM\Table(name: 'xi_child')]
#[ORM\Index(name: 'idx_xi_child_owner', columns: ['owner'])]
class XiChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: XiParent::class)]
    #[ORM\JoinColumn(name: 'parent_id', onDelete: 'CASCADE')]
    public ?XiParent $parent = null;

    #[ORM\Column(length: 64)]
    public string $owner = '';
}
