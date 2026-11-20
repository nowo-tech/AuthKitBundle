<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

/**
 * Gives the host's identifier column (`kernel_users.email`) the collation of the test: a folding one (an application
 * before the binary-collation migration) or a binary one (after it).
 */
final readonly class UserEmailCollationListener
{
    /**
     * @param non-empty-string $collation
     */
    public function __construct(private string $collation)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $columns = PasswordResetCollationListener::mappedColumns($args->getEntityManager(), $args->getSchema(), [KernelUser::class => ['email']]);
        PasswordResetCollationListener::collateSchema($args, $columns, $this->collation);
    }
}
