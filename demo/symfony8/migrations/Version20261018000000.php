<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuthKit QR phone login challenges (demo: qr_login.mode enabled)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auth_kit_qr_login_challenge (id VARCHAR(36) NOT NULL, public_code VARCHAR(8) NOT NULL, status VARCHAR(16) NOT NULL, user_class VARCHAR(255) DEFAULT NULL, user_id VARCHAR(64) DEFAULT NULL, phone_hint VARCHAR(32) DEFAULT NULL, desktop_cookie_hash VARCHAR(64) NOT NULL, desktop_ip_hash VARCHAR(64) NOT NULL, desktop_ua_hash VARCHAR(64) NOT NULL, desktop_ua_label VARCHAR(128) NOT NULL, approve_token_hash VARCHAR(64) NOT NULL, approve_token_used_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL, approved_at DATETIME DEFAULT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A12A96D3C487249 (public_code), INDEX idx_qr_challenge_status_expires (status, expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_kit_qr_login_challenge');
    }
}
