<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103112449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuthKit 1.34 MFA + account switcher, UserKit 1.11 sessions (demo: mfa and multi_account enabled; also the social tables and 1.33 last_sent_by)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE auth_kit_account_vault (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(32) NOT NULL, profile VARCHAR(64) NOT NULL, user_identifier VARCHAR(191) NOT NULL, token_hash VARCHAR(64) NOT NULL, previous_token_hash VARCHAR(64) DEFAULT NULL, rotated_at DATETIME NOT NULL, sealed LONGTEXT DEFAULT NULL, previous_sealed LONGTEXT DEFAULT NULL, rotated_by VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_auth_kit_account_vault_previous (previous_token_hash), INDEX idx_auth_kit_account_vault_user (profile, user_identifier), INDEX idx_auth_kit_account_vault_expires (expires_at), UNIQUE INDEX uniq_auth_kit_account_vault_public_id (public_id), UNIQUE INDEX uniq_auth_kit_account_vault_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_kit_mfa (id INT AUTO_INCREMENT NOT NULL, totp_secret LONGTEXT DEFAULT NULL, enabled_at DATETIME DEFAULT NULL, last_used_step BIGINT DEFAULT NULL, required_since DATETIME DEFAULT NULL, reset_at DATETIME DEFAULT NULL, reset_by VARCHAR(180) DEFAULT NULL, updated_at DATETIME NOT NULL, profile VARCHAR(64) NOT NULL, user_type VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_auth_kit_mfa_owner (profile, user_type, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_kit_mfa_recovery_code (id INT AUTO_INCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, mfa_id INT NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_auth_kit_mfa_recovery_code (mfa_id, code_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_kit_mfa_trusted_device (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(32) NOT NULL, mfa_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, label VARCHAR(64) NOT NULL, device_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_auth_kit_mfa_device_mfa (mfa_id, expires_at), UNIQUE INDEX uniq_auth_kit_mfa_device_public_id (public_id), UNIQUE INDEX uniq_auth_kit_mfa_device_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_kit_social_account (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(64) NOT NULL, provider_user_id VARCHAR(255) NOT NULL, user_class VARCHAR(255) NOT NULL, user_id VARCHAR(64) NOT NULL, user_identifier VARCHAR(255) NOT NULL, access_token LONGTEXT DEFAULT NULL, refresh_token LONGTEXT DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, display_name VARCHAR(255) DEFAULT NULL, raw_profile JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_auth_kit_social_account_provider_subject_class (provider, provider_user_id, user_class), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE auth_kit_social_credential (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(64) NOT NULL, label VARCHAR(128) NOT NULL, client_id VARCHAR(255) NOT NULL, client_secret LONGTEXT NOT NULL, enabled TINYINT NOT NULL, enterprise_sso TINYINT DEFAULT 0 NOT NULL, scopes JSON NOT NULL, authorize_url VARCHAR(512) DEFAULT NULL, token_url VARCHAR(512) DEFAULT NULL, userinfo_url VARCHAR(512) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_auth_kit_social_credential_provider (provider), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_kit_session (public_id VARCHAR(32) NOT NULL, user_type VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, session_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, browser VARCHAR(32) NOT NULL, os VARCHAR(32) NOT NULL, ip VARCHAR(64) DEFAULT NULL, device_id VARCHAR(64) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revoked_reason VARCHAR(32) DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, INDEX idx_user_kit_session_user (user_type, user_id, revoked_at, last_seen_at), INDEX idx_user_kit_session_last_seen (last_seen_at), UNIQUE INDEX uniq_user_kit_session_public_id (public_id), UNIQUE INDEX uniq_user_kit_session_owner (session_hash, user_type, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_kit_session_epoch (user_type VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, epoch_at DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX uniq_user_kit_session_epoch_owner (user_type, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE auth_kit_invitation ADD last_sent_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE users CHANGE password_reset_expires_at password_reset_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE auth_kit_account_vault');
        $this->addSql('DROP TABLE auth_kit_mfa');
        $this->addSql('DROP TABLE auth_kit_mfa_recovery_code');
        $this->addSql('DROP TABLE auth_kit_mfa_trusted_device');
        $this->addSql('DROP TABLE auth_kit_social_account');
        $this->addSql('DROP TABLE auth_kit_social_credential');
        $this->addSql('DROP TABLE user_kit_session');
        $this->addSql('DROP TABLE user_kit_session_epoch');
        $this->addSql('ALTER TABLE auth_kit_invitation DROP last_sent_by');
        $this->addSql('ALTER TABLE users CHANGE password_reset_expires_at password_reset_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
