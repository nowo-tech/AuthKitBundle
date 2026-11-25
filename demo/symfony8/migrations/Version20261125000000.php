<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AuthKit 2.4.0 passkeys (demo: webauthn.mode enabled): the table of src/Resources/sql/webauthn/mysql.up.sql.
 */
final class Version20261125000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuthKit 2.4.0 passkeys: auth_kit_webauthn_credential';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auth_kit_webauthn_credential (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, profile VARCHAR(64) NOT NULL, user_type VARCHAR(191) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, user_id VARCHAR(191) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, credential_id_hash VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, credential_id LONGTEXT NOT NULL, user_handle VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`, public_key LONGTEXT NOT NULL, sign_count BIGINT NOT NULL, transports JSON NOT NULL, aaguid VARCHAR(36) DEFAULT NULL, backup_eligible TINYINT DEFAULT NULL, backup_state TINYINT DEFAULT NULL, label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, INDEX idx_auth_kit_webauthn_owner (profile, user_type, user_id), UNIQUE INDEX uniq_auth_kit_webauthn_public_id (public_id), UNIQUE INDEX uniq_auth_kit_webauthn_credential (credential_id_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_kit_webauthn_credential');
    }
}
