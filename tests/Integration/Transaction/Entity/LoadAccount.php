<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An application entity of the transaction runner tests (1.50.0).
 */
#[ORM\Entity]
#[ORM\Table(name: 'load_account')]
class LoadAccount
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        public string $id,
        #[ORM\Column(length: 64)]
        public string $name = '',
    ) {
    }
}
