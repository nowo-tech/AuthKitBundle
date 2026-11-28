<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml\Event;

use Nowo\AuthKitBundle\Saml\SamlFailure;

/**
 * A SAML sign-in was refused at the ACS (2.10.0), for audit. `reason` is a {@see SamlFailure} code; no response,
 * assertion, NameID or attribute data is carried. Dispatched for every refused relayed POST (unsolicited and crafted
 * ones included), never for the first, cross-site POST that is only relayed.
 */
final readonly class SamlResponseRejectedEvent
{
    public function __construct(
        public string $profile,
        public string $provider,
        public string $reason,
    ) {
    }
}
