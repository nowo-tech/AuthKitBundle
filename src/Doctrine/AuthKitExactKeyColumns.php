<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;

/**
 * The bundle's own declaration of the identity / access-key columns of its tables (REQ-SEC-006).
 *
 * These are the columns an account is found by, and in three of these tables they are a *unique* key, which is what
 * makes a folding collation dangerous rather than untidy: on the MySQL / MariaDB defaults `jose` = `JOSÉ` = `jose `,
 * so one account's insert collides with a look-alike account's row, and one account's lookup returns it. The bundle
 * keeps them on the server's binary NO PAD collation when it builds the schema
 * ({@see IdentityCollationListener}, 1.47.0; {@see PasswordResetCollationListener}, 1.46.0) and re-checks every row
 * with `===` on top.
 *
 * What was missing is the *assertion*. Until now only a host's hand-kept list knew that, say,
 * `auth_kit_mfa.user_id` is an identity key, so a table created before those listeners, restored from a dump or
 * imported by hand kept the host's folding default and nothing said so. This declaration is what
 * `nowo:migrations:check-key-collations` checks; MigrationsKit discovers it by autoconfiguration, so a host
 * registers **nothing** and can delete the `nowo_migrations_kit.collation_check.ignore_columns` entries it kept for
 * these columns.
 *
 * Declared: the MFA owner (`user_type`, `user_id`), the social link's provider subject and owner
 * (`provider_user_id`, `user_id`, `user_identifier`), the account switcher's owner (`user_identifier`), the
 * invitation's addressee and author (`email`, `invited_by`) and the password reset's owner and credential digests
 * (`user_id`, `user_identifier`, `token_hash`, `code_hash` — a reset link is redeemed by matching the digest, so a
 * folding collation widens what one link redeems).
 *
 * Deliberately **not** declared, because a declaration is a claim about identity and an untrue one is worse than
 * none (on a server with no NO PAD collation it reports `fallback` about a column that does not want NO PAD):
 *
 * - `profile`, `provider` and `user_class`, although they are part of the same unique keys: each is a name the
 *   *application* chooses in its configuration (a profile key, a provider key, a PHP class), validated against a
 *   pattern and never submitted by a visitor. Nobody can make two of them look alike.
 * - The state and bookkeeping columns (`status`, `accepted_at`, `revoked_at`, `last_used_step`, `rotated_by`, the
 *   stored tokens of a social account) and the integer surrogate `id` columns, which carry no collation at all.
 * - The other credential digests and public ids of this bundle — `auth_kit_invitation.{public_id,token_hash}`,
 *   `auth_kit_account_vault.{public_id,token_hash,previous_token_hash}`,
 *   `auth_kit_mfa_trusted_device.{public_id,token_hash}`, `auth_kit_mfa_recovery_code.code_hash` and the
 *   `auth_kit_qr_login_challenge` hashes — *are* access keys, but the bundle does not give them an exact collation
 *   today, and a declaration whose column was never collated reports a failure on every host. Raising them is a
 *   schema decision (mapping plus an `ALTER` for existing tables) for its own release, not something a declaration
 *   can do by itself.
 *
 * The table names are resolved from `nowo_auth_kit.doctrine.table_prefix`, never literals. The columns are declared
 * **inactive** (reported as skipped with the setting that gates them, never as a missing table) when the entities are
 * not mapped at all (`doctrine.register_mapping`), and the reset table's columns also when no profile stores its
 * reset requests in the table.
 *
 * Stateless and database-free (REQ-RUNTIME-002): the list is rebuilt on every call, so the service is safe to keep
 * between requests in a FrankenPHP worker with `kernel.reset: false`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AuthKitExactKeyColumns implements ExactKeyColumnsInterface
{
    /** The package that owns the tables, as reported by the aggregate run. */
    public const OWNER = 'nowo-tech/auth-kit-bundle';

    /** Condition reported when the entities are not mapped. */
    public const MAPPING_CONDITION = 'nowo_auth_kit.doctrine.register_mapping: the AuthKit entities are mapped';

    /** Condition reported when no profile keeps its password reset requests in the table. */
    public const RESET_TABLE_CONDITION = 'nowo_auth_kit.profiles.*.password_reset.storage: table';

    /**
     * @param string $tablePrefix nowo_auth_kit.doctrine.table_prefix
     * @param bool $mappingEnabled resolved nowo_auth_kit.doctrine.register_mapping (the entities are mapped)
     * @param bool $resetTable a profile uses password_reset.storage: table (the reset table exists)
     */
    public function __construct(
        private string $tablePrefix = '',
        private bool $mappingEnabled = true,
        private bool $resetTable = false,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $mapped    = $this->mappingEnabled ? null : self::MAPPING_CONDITION;
        $resetOn   = $this->mappingEnabled && $this->resetTable;
        $resetWhen = $resetOn ? null : ($this->mappingEnabled ? self::RESET_TABLE_CONDITION : self::MAPPING_CONDITION);

        $mfa        = $this->tablePrefix . MfaCredential::TABLE;
        $social     = $this->tablePrefix . SocialLoginAccount::TABLE;
        $vault      = $this->tablePrefix . AccountVaultEntry::TABLE;
        $invitation = $this->tablePrefix . Invitation::TABLE;
        $reset      = $this->tablePrefix . PasswordResetRequest::TABLE;

        return [
            new ExactKeyColumn($mfa, 'user_type', self::OWNER, 'MFA owner (class half), part of the unique key of the credential', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($mfa, 'user_id', self::OWNER, 'MFA owner (id half), part of the unique key of the credential', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($social, 'provider_user_id', self::OWNER, 'subject of the social provider: the unique key a login is matched on', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($social, 'user_id', self::OWNER, 'owner of the social link', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($social, 'user_identifier', self::OWNER, 'owner identifier of the social link (personal-data erasure)', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($vault, 'user_identifier', self::OWNER, 'owner of an account-switcher entry', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($invitation, 'email', self::OWNER, 'addressee of the invitation: who may accept it', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($invitation, 'invited_by', self::OWNER, 'author of the invitation', $mapped, $this->mappingEnabled),
            new ExactKeyColumn($reset, 'user_id', self::OWNER, 'owner of the reset request', $resetWhen, $resetOn),
            new ExactKeyColumn($reset, 'user_identifier', self::OWNER, 'owner identifier of the reset request', $resetWhen, $resetOn),
            new ExactKeyColumn($reset, 'token_hash', self::OWNER, 'digest a reset link is redeemed by', $resetWhen, $resetOn),
            new ExactKeyColumn($reset, 'code_hash', self::OWNER, 'digest a reset code is redeemed by', $resetWhen, $resetOn),
        ];
    }
}
