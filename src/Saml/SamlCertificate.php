<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;

use function base64_decode;
use function base64_encode;
use function chunk_split;
use function hash;
use function is_array;
use function is_int;
use function openssl_pkey_get_private;
use function openssl_x509_check_private_key;
use function openssl_x509_parse;
use function preg_match;
use function preg_replace;
use function strlen;
use function trim;

/**
 * An X.509 certificate of a SAML provider row (2.10.0): the normalised PEM, the lowercase hex SHA-256 of its DER and
 * its validity dates, so expiry can be queried in SQL. A certificate is public data: it is stored in plain (IdP) or
 * encrypted with its private key (SP slots). Immutable, stateless.
 */
final readonly class SamlCertificate
{
    /** Longest PEM accepted (a 16384-bit RSA certificate with extensions stays well below it). */
    public const MAX_PEM_LENGTH = 16384;

    private function __construct(
        public string $pem,
        public string $sha256,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
    ) {
    }

    /**
     * Parses a PEM certificate, or the bare base64 body IdP metadata carries (`ds:X509Certificate`).
     *
     * @throws InvalidArgumentException when the value is not one readable certificate
     */
    public static function fromPem(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_PEM_LENGTH) {
            throw new InvalidArgumentException('The certificate is empty or too long.');
        }
        if (preg_match('/^-----BEGIN CERTIFICATE-----\s*(?<body>[A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----$/', $value, $match) === 1) {
            $body = $match['body'];
        } elseif (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value) === 1) {
            $body = $value;
        } else {
            throw new InvalidArgumentException('The certificate must be one PEM "CERTIFICATE" block or its base64 body.');
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);
        if ($der === false || $der === '') {
            throw new InvalidArgumentException('The certificate body is not valid base64.');
        }
        $pem    = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed) || !is_int($parsed['validFrom_time_t'] ?? null) || !is_int($parsed['validTo_time_t'] ?? null)) {
            throw new InvalidArgumentException('The certificate cannot be read.');
        }

        return new self(
            $pem,
            hash('sha256', $der),
            (new DateTimeImmutable())->setTimestamp($parsed['validFrom_time_t']),
            (new DateTimeImmutable())->setTimestamp($parsed['validTo_time_t']),
        );
    }

    /**
     * Whether the PEM private key is readable and belongs to this certificate.
     */
    public function matchesPrivateKey(string $privateKeyPem): bool
    {
        $key = @openssl_pkey_get_private($privateKeyPem);

        return $key instanceof OpenSSLAsymmetricKey && openssl_x509_check_private_key($this->pem, $key);
    }
}
