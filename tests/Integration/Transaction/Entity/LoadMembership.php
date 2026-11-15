<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An application entity pointing at a {@see LoadAccount} (the runner detaches it with the account it points at).
 */
#[ORM\Entity]
#[ORM\Table(name: 'load_membership')]
class LoadMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LoadAccount::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?LoadAccount $account = null,
        #[ORM\Column(length: 64)]
        public string $role = 'member',
    ) {
    }
}
