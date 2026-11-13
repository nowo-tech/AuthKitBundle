<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

use function method_exists;

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

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        if ($args->getClassMetadata()->getName() !== KernelUser::class) {
            return;
        }
        // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
        $edited = PasswordResetCollationListener::collate($args->getClassTable(), 'email', $this->collation, method_exists(Table::class, 'edit'));
        if ($edited instanceof Table) {
            $args->setClassTable($edited);
        }
    }
}
