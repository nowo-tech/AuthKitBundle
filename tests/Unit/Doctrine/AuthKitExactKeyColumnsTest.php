<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Nowo\AuthKitBundle\Doctrine\AuthKitExactKeyColumns;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * REQ-SEC-006: the declaration names the identity and credential columns of the five tables, on the *configured*
 * table names, and declares a table that does not exist inactive instead of omitting it.
 */
final class AuthKitExactKeyColumnsTest extends TestCase
{
    public function testItDeclaresTheIdentityColumnsOfTheSixTables(): void
    {
        $columns = (new AuthKitExactKeyColumns('', true, true))->exactKeyColumns();

        self::assertSame([
            'auth_kit_mfa.user_type',
            'auth_kit_mfa.user_id',
            'auth_kit_social_account.provider_user_id',
            'auth_kit_social_account.user_id',
            'auth_kit_social_account.user_identifier',
            'auth_kit_account_vault.user_identifier',
            'auth_kit_invitation.email',
            'auth_kit_invitation.invited_by',
            'auth_kit_webauthn_credential.user_type',
            'auth_kit_webauthn_credential.user_id',
            'auth_kit_webauthn_credential.credential_id_hash',
            'auth_kit_webauthn_credential.user_handle',
            'auth_kit_webauthn_credential.public_id',
            'auth_kit_password_reset.user_id',
            'auth_kit_password_reset.user_identifier',
            'auth_kit_password_reset.token_hash',
            'auth_kit_password_reset.code_hash',
        ], array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns));

        foreach ($columns as $column) {
            self::assertTrue($column->active, $column->describe());
            self::assertNull($column->condition);
            self::assertSame(AuthKitExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose, $column->describe());
        }
    }

    public function testItResolvesTheConfiguredTablePrefix(): void
    {
        foreach ((new AuthKitExactKeyColumns('app_', true, true))->exactKeyColumns() as $column) {
            self::assertStringStartsWith('app_auth_kit_', $column->table, $column->describe());
        }
    }

    public function testWithoutTheMappingEveryColumnIsInactive(): void
    {
        $columns = (new AuthKitExactKeyColumns('', false, true))->exactKeyColumns();

        self::assertCount(17, $columns);
        foreach ($columns as $column) {
            self::assertFalse($column->active, $column->describe());
            self::assertSame(AuthKitExactKeyColumns::MAPPING_CONDITION, $column->condition, $column->describe());
        }
    }

    public function testTheResetTableIsGatedOnItsOwnStorageSetting(): void
    {
        // Mapped entities, but no profile keeps its reset requests in the table: only that table is missing.
        foreach ((new AuthKitExactKeyColumns())->exactKeyColumns() as $column) {
            $reset = $column->table === 'auth_kit_password_reset';
            self::assertSame(!$reset, $column->active, $column->describe());
            self::assertSame($reset ? AuthKitExactKeyColumns::RESET_TABLE_CONDITION : null, $column->condition, $column->describe());
        }
    }

    public function testItIsSafeToCallMoreThanOnce(): void
    {
        // REQ-RUNTIME-002: nothing is memoised, so a worker that keeps the service gets the same answer every time.
        $declaration = new AuthKitExactKeyColumns('app_', true, true);

        self::assertEquals($declaration->exactKeyColumns(), $declaration->exactKeyColumns());
    }
}
