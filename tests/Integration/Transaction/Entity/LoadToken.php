<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row with a pre-insert id generator and a unique key, for AuthKitRows (1.50.0).
 */
#[ORM\Entity]
#[ORM\Table(name: 'load_token')]
class LoadToken
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: LoadTokenIdGenerator::class)]
    public ?string $id = null;

    public function __construct(
        #[ORM\Column(length: 32, unique: true)]
        public string $code,
    ) {
    }
}
