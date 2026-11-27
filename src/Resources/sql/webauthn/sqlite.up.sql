-- AuthKit 2.4.0 (rp_id: 2.6.0): passkey table auth_kit_webauthn_credential, SQLite 3.
-- Generated from the ORM mapping of Nowo\AuthKitBundle\Entity\WebauthnCredential; checked against it in CI.
-- With nowo_auth_kit.doctrine.table_prefix, prefix the table and index names.
CREATE TABLE auth_kit_webauthn_credential (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id VARCHAR(32) NOT NULL, profile VARCHAR(64) NOT NULL, user_type VARCHAR(191) NOT NULL, user_id VARCHAR(191) NOT NULL, credential_id_hash VARCHAR(64) NOT NULL, credential_id CLOB NOT NULL, user_handle VARCHAR(64) NOT NULL, public_key CLOB NOT NULL, sign_count BIGINT NOT NULL, transports CLOB NOT NULL, aaguid VARCHAR(36) DEFAULT NULL, backup_eligible BOOLEAN DEFAULT NULL, backup_state BOOLEAN DEFAULT NULL, label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, rp_id VARCHAR(253) DEFAULT NULL);
CREATE INDEX idx_auth_kit_webauthn_owner ON auth_kit_webauthn_credential (profile, user_type, user_id);
CREATE UNIQUE INDEX uniq_auth_kit_webauthn_public_id ON auth_kit_webauthn_credential (public_id);
CREATE UNIQUE INDEX uniq_auth_kit_webauthn_credential ON auth_kit_webauthn_credential (credential_id_hash);
