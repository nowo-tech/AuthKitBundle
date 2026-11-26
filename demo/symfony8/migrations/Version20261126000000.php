<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AuthKit 2.6.0 (docs/UPGRADING.md "To 2.6.0"): the RP id each passkey was created for. Existing demo passkeys keep a
 * null rp_id (created for the RP id configured then: the demo's request host).
 */
final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AuthKit 2.6.0 passkeys: auth_kit_webauthn_credential.rp_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_kit_webauthn_credential ADD rp_id VARCHAR(253) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_kit_webauthn_credential DROP rp_id');
    }
}
