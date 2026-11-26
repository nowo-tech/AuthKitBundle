<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use Nowo\AuthKitBundle\ValueObject\WebAuthnRelyingPartyContext;
use Nowo\AuthKitBundle\Webauthn\RelyingParty\ConfiguredWebAuthnRelyingPartyProvider;

/**
 * The WebAuthn relying party of a passkey ceremony (2.6.0): its RP id and the exact origins a ceremony may come from.
 * Every ceremony (registration and sign-in options, their verification and the origin check) asks it again, per
 * request; nothing reads `webauthn.rp_id` or `webauthn.allowed_origins` directly.
 *
 * The default ({@see ConfiguredWebAuthnRelyingPartyProvider}) answers the profile's configuration, as before 2.6.0.
 * Alias this interface to your service to keep the values elsewhere (an instance setting in a database); decorate it
 * to fall back to the configuration. Your implementation is called on every ceremony: read the current value each time
 * and keep nothing between requests (FrankenPHP worker mode with the kernel reset off), or implement
 * `Symfony\Contracts\Service\ResetInterface`.
 *
 * AuthKit validates every answer ({@see WebAuthnRelyingPartyValidator}). An invalid answer, or an exception, disables
 * the passkeys of that ceremony (fail closed): no options are issued, nothing is verified, the page asks the person to
 * use the password, and the failure is logged.
 */
interface WebAuthnRelyingPartyProviderInterface
{
    /**
     * The RP id: a registrable host name, lower case, without scheme or port (e.g. `example.com`).
     */
    public function rpId(WebAuthnRelyingPartyContext $context): string;

    /**
     * The exact origins (`https://host[:port]`, lower case, no path) a ceremony may come from; each host is the RP id
     * or a sub-domain of it. `http://localhost[:port]` only in development.
     *
     * @return list<string>
     */
    public function allowedOrigins(WebAuthnRelyingPartyContext $context): array;
}
