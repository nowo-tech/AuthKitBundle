<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use LogicException;

use function sprintf;

/**
 * 2.8.0: an enterprise credential (`enterprise_sso`) holding a client secret was about to be written while
 * nowo-tech/doctrine-encrypt-bundle does not encrypt on the entity manager that writes it. Nothing was written (thrown
 * from `onFlush`, before the flush opens its transaction). Never carries the secret.
 */
final class EnterpriseSecretNotEncryptedException extends LogicException
{
    public static function forProvider(string $provider): self
    {
        return new self(sprintf('AuthKit refuses to store the client secret of the enterprise credential "%s" unencrypted: register and configure nowo-tech/doctrine-encrypt-bundle on the entity manager that writes auth_kit_social_credential (see docs/SECURITY.md). Nothing was written.', $provider));
    }

    /**
     * 2.10.0: a SAML provider row holding an SP private key (current or next slot).
     */
    public static function forSamlProvider(string $provider): self
    {
        return new self(sprintf('AuthKit refuses to store the SP private key of the SAML provider "%s" unencrypted: register and configure nowo-tech/doctrine-encrypt-bundle on the entity manager that writes auth_kit_saml_provider (see docs/SECURITY.md). Nothing was written.', $provider));
    }
}
