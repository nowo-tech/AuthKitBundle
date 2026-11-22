<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Psr\Log\LoggerInterface;

use function array_keys;

/**
 * Binary NO PAD collation of the identity columns used as keys on MySQL / MariaDB (REQ-SEC-006, 1.47.0):
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
 *
 * Since 1.54.1 (audit XI): applied on `postGenerateSchema` once every foreign key exists, the same way as
 * {@see PasswordResetCollationListener} (1.47.0–1.54.0 replaced the tables on `postGenerateSchemaTable`, so ORM 3.7–3.8
 * dropped any foreign key starting on them on MySQL / MariaDB; none exist today: latent). The platform is the one of the
 * schema tool's entity manager; a manager that does not map these entities is left alone. Stateless (worker safe).
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

    /**
     * @param ManagerRegistry|null $doctrine unused since 1.54.1 (the platform comes from the schema tool's entity
     *                                       manager); kept for BC
     */
    public function __construct(
        ?ManagerRegistry $doctrine = null,
        private ?LoggerInterface $logger = null,
    ) {
        unset($doctrine);
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $columns = PasswordResetCollationListener::mappedColumns($manager, $args->getSchema(), self::FIELDS);
        if ($columns === []) {
            return;
        }
        $collation = PasswordResetCollationListener::collationFor($manager->getConnection()->getDatabasePlatform());
        if ($collation === null) {
            return;
        }
        foreach (array_keys($columns) as $table) {
            PasswordResetCollationListener::warnOnPadSpace($collation, $table, $this->logger);
        }
        PasswordResetCollationListener::collateSchema($args, $columns, $collation);
    }
}
