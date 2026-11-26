<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\RelyingParty;

use Nowo\AuthKitBundle\ValueObject\WebAuthnRelyingPartyContext;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;

use function array_map;
use function strtolower;

/**
 * The default {@see WebAuthnRelyingPartyProviderInterface} (2.6.0): the profile's `webauthn.rp_id` (null: the request
 * host) and `webauthn.allowed_origins` (empty: exactly `https://<rp id>`, audit ZBX-M1: never the request `Host`; the
 * only exception is a `localhost` RP id served on localhost, where the request origin is used). The behaviour of 2.5.
 *
 * Stateless: a pure function of the context (worker safe). Decorate the interface to fall back to it.
 */
final readonly class ConfiguredWebAuthnRelyingPartyProvider implements WebAuthnRelyingPartyProviderInterface
{
    public function rpId(WebAuthnRelyingPartyContext $context): string
    {
        return $context->configuredRpId !== null && $context->configuredRpId !== '' ? $context->configuredRpId : $context->requestHost;
    }

    public function allowedOrigins(WebAuthnRelyingPartyContext $context): array
    {
        if ($context->configuredOrigins !== []) {
            // 2.4.1 (audits ZBX-v-I3, ZCC-L1): lower-cased after env resolution (a browser origin is lower case).
            return array_map(strtolower(...), $context->configuredOrigins);
        }
        // Audit ZCQ-M1: the RP id the ceremony resolved (a decorator's), never one derived again from the request.
        $rpId = $context->resolvedRpId ?? $this->rpId($context);

        return $rpId === 'localhost' && $context->requestHost === 'localhost' ? [$context->requestOrigin] : ['https://' . $rpId];
    }
}
