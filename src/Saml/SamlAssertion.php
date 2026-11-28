<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

/**
 * The data of a SAML assertion that passed every check of {@see SamlResponseValidator} (2.10.0), read from the signed
 * (and decrypted) assertion only. Personal data: never log it.
 */
final readonly class SamlAssertion
{
    /**
     * @param array<string, list<string>> $attributes by `Name`
     * @param array<string, list<string>> $friendlyAttributes by `FriendlyName`
     * @param list<string> $authnContextClassRefs
     */
    public function __construct(
        public string $issuer,
        public ?string $nameId,
        public ?string $nameIdFormat,
        public ?string $sessionIndex,
        public array $attributes,
        public array $friendlyAttributes,
        public array $authnContextClassRefs,
        public ?string $assertionId,
    ) {
    }

    /**
     * The values of an attribute by `Name`, else by `FriendlyName`.
     *
     * @return list<string>
     */
    public function values(string $name): array
    {
        return $this->attributes[$name] ?? $this->friendlyAttributes[$name] ?? [];
    }
}
