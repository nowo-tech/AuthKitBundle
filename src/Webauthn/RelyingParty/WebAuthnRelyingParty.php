<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\RelyingParty;

/**
 * A validated relying party of one ceremony (2.6.0): RP id and allowed origins, lower case. Built per call by
 * {@see WebAuthnRelyingPartyResolver}, never stored.
 *
 * @internal
 */
final readonly class WebAuthnRelyingParty
{
    /**
     * @param list<string> $origins
     */
    public function __construct(
        public string $rpId,
        public array $origins,
    ) {
    }
}
