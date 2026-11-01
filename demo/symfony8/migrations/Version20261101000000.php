<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuthKit invitations (demo: invitations.mode enabled)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auth_kit_invitation (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(32) NOT NULL, profile VARCHAR(64) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, payload JSON NOT NULL, message LONGTEXT DEFAULT NULL, locale VARCHAR(16) DEFAULT NULL, token_hash VARCHAR(64) NOT NULL, ttl_days SMALLINT NOT NULL, expires_at DATETIME NOT NULL, sent_at DATETIME NOT NULL, send_count INT NOT NULL, invited_by VARCHAR(180) DEFAULT NULL, accepted_at DATETIME DEFAULT NULL, accepted_by VARCHAR(180) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revoked_by VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_auth_kit_invitation_public_id (public_id), UNIQUE INDEX uniq_auth_kit_invitation_token (token_hash), INDEX idx_auth_kit_invitation_email (profile, email, accepted_at, revoked_at), INDEX idx_auth_kit_invitation_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_kit_invitation');
    }
}
