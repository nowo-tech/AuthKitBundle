<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Webauthn;

use Nowo\AuthKitBundle\ValueObject\WebAuthnRelyingPartyContext;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;
use RuntimeException;

/**
 * A host's relying party provider (2.6.0 tests), the way Beacon keeps it: the values of {@see RelyingPartySettings},
 * read on every call, falling back to the decorated default (the configuration). Keeps nothing itself. The fallback
 * origins come from the inner provider with the resolved RP id in the context (audit ZCQ-M1): never the request Host.
 */
final readonly class SettingsRelyingPartyProvider implements WebAuthnRelyingPartyProviderInterface
{
    public function __construct(
        private RelyingPartySettings $settings,
        private WebAuthnRelyingPartyProviderInterface $inner,
    ) {
    }

    public function rpId(WebAuthnRelyingPartyContext $context): string
    {
        if ($this->settings->down) {
            throw new RuntimeException('The settings store is down.');
        }

        return $this->settings->rpId ?? $this->inner->rpId($context);
    }

    public function allowedOrigins(WebAuthnRelyingPartyContext $context): array
    {
        return $this->settings->origins ?? $this->inner->allowedOrigins($context);
    }
}
