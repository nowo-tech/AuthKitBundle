<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * A generated SP key pair (2.10.0): PEM private key (secret: store it only through {@see \Nowo\AuthKitBundle\Entity\SamlProvider}
 * setters, never log or display it) and its self-signed certificate.
 */
final readonly class SamlSpKeyPair
{
    public function __construct(
        #[SensitiveParameter]
        public string $privateKeyPem,
        public string $certificatePem,
        public DateTimeImmutable $notAfter,
    ) {
    }
}
