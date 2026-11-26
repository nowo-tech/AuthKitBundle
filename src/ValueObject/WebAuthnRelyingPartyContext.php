<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;

/**
 * What a {@see WebAuthnRelyingPartyProviderInterface} is asked about (2.6.0): one passkey
 * ceremony of one AuthKit profile, built per call by the bundle and never stored (FrankenPHP worker mode, kernel reset
 * false). Framework-free (REQ-ARCH-001 rule 3): no Symfony `Request`.
 *
 * `configuredRpId` and `configuredOrigins` are the profile's `webauthn.rp_id` and `webauthn.allowed_origins` (after
 * `%env()%` resolution), so a provider can fall back to them. `requestHost` and `requestOrigin` describe the current
 * request (Symfony trusted proxies applied); the bundle's default provider uses them only when `rp_id` is null and for a
 * `localhost` RP id served on localhost (development). `resolvedRpId` (2.6.0, audit ZCQ-M1) is the RP id of this
 * ceremony when the origins are asked: default origins must be derived from it, never from the request `Host`.
 */
final readonly class WebAuthnRelyingPartyContext
{
    /**
     * @param string $profile the AuthKit profile name
     * @param string $requestHost the request host, lower case, without port
     * @param string $requestOrigin the request origin (`scheme://host[:port]`)
     * @param string|null $configuredRpId `webauthn.rp_id` (null: the request host)
     * @param list<string> $configuredOrigins `webauthn.allowed_origins` (empty: `https://<rp id>`)
     * @param string|null $resolvedRpId the RP id this ceremony resolved (set when the origins are asked; null when the
     *                                  RP id itself is asked)
     */
    public function __construct(
        public string $profile,
        public string $requestHost,
        public string $requestOrigin,
        public ?string $configuredRpId = null,
        public array $configuredOrigins = [],
        public ?string $resolvedRpId = null,
    ) {
    }

    /**
     * The same context with the RP id the ceremony resolved (audit ZCQ-M1: the origins follow it).
     */
    public function withRpId(string $rpId): self
    {
        return new self($this->profile, $this->requestHost, $this->requestOrigin, $this->configuredRpId, $this->configuredOrigins, $rpId);
    }
}
