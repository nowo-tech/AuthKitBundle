<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Psr\Log\LoggerInterface;

use function method_exists;

/**
 * Binary NO PAD collation of the identity columns used as keys on MySQL / MariaDB (REQ-SEC-006, 1.46.1):
 *
 * - MFA (`auth_kit_mfa`): `user_type`, `user_id` (the unique key `uniq_auth_kit_mfa_owner`);
 * - social login (`auth_kit_social_account`): `provider_user_id` (the unique key of the provider subject), `user_id`,
 *   `user_identifier` (personal-data erasure);
 * - account switcher (`auth_kit_account_vault`): `user_identifier`;
 * - invitations (`auth_kit_invitation`): `email`, `invited_by`.
 *
 * The default MySQL / MariaDB collations are case-, accent- and (PAD SPACE) trailing-space-insensitive, so `jose` =
 * `JOSÉ` = `jose `: a folding unique key lets one user's insert collide with (or a lookup return) a look-alike user's
 * row. The code re-checks every row with `===` as well; the collation keeps the database exact. Same rules as
 * {@see PasswordResetCollationListener} (MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+ `utf8mb4_0900_bin`, MySQL 5.7
 * `utf8mb4_bin`, PostgreSQL / SQLite untouched), plus a warning when the PAD SPACE fallback is used (item 8). Applied
 * to the generated schema (schema tool, `doctrine:migrations:diff`); existing tables need the ALTERs of UPGRADING.
 * Stateless (worker safe).
 *
 * @internal
 */
final readonly class IdentityCollationListener
{
    /** @var array<class-string, list<string>> entity => fields */
    public const FIELDS = [
        MfaCredential::class      => ['userType', 'userId'],
        SocialLoginAccount::class => ['providerUserId', 'userId', 'userIdentifier'],
        AccountVaultEntry::class  => ['userIdentifier'],
        Invitation::class         => ['email', 'invitedBy'],
    ];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $fields   = self::FIELDS[$metadata->getName()] ?? null;
        if ($fields === null) {
            return;
        }
        $manager   = $this->doctrine->getManagerForClass($metadata->getName());
        $platform  = $manager instanceof EntityManagerInterface ? $manager->getConnection()->getDatabasePlatform() : null;
        $collation = $platform !== null ? PasswordResetCollationListener::collationFor($platform) : null;
        if ($collation === null) {
            return;
        }
        PasswordResetCollationListener::warnOnPadSpace($collation, $metadata->getTableName(), $this->logger);

        $table = $args->getClassTable();
        foreach ($fields as $field) {
            /** @var non-empty-string $column mapped column names are never empty */
            $column = $metadata->getColumnName($field);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = PasswordResetCollationListener::collate($table, $column, $collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }
}
