-- AuthKit 2.4.0: removes the passkey table auth_kit_webauthn_credential (mysql). Every registered passkey is lost.
DROP TABLE auth_kit_webauthn_credential;
