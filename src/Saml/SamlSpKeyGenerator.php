<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

use function bin2hex;
use function hexdec;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function preg_match;
use function random_bytes;

use const OPENSSL_KEYTYPE_RSA;

/**
 * Generates an SP key pair for a provider slot (2.10.0): RSA 3072 and a self-signed SHA-256 certificate (the IdP trusts
 * it from the metadata; no CA is involved). Use it for the first pair and for the next pair of a rotation. Stateless.
 */
final class SamlSpKeyGenerator
{
    public const KEY_BITS = 3072;

    /**
     * @param string $commonName the certificate subject CN (e.g. the application host); 1 to 64 printable characters
     * @param int $validDays 30 to 3650
     *
     * @throws InvalidArgumentException on an invalid name or duration
     * @throws RuntimeException when OpenSSL cannot generate the pair
     */
    public function generate(string $commonName, int $validDays = 730): SamlSpKeyPair
    {
        if (preg_match('/^[\x20-\x7e]{1,64}$/', $commonName) !== 1 || $validDays < 30 || $validDays > 3650) {
            throw new InvalidArgumentException('The common name is 1 to 64 printable ASCII characters and the validity 30 to 3650 days.');
        }
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::KEY_BITS, 'digest_alg' => 'sha256'];
        $key     = openssl_pkey_new($options);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('OpenSSL could not generate the SP private key.'); // @codeCoverageIgnore
        }
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('OpenSSL could not build the SP certificate request.'); // @codeCoverageIgnore
        }
        $certificate = openssl_csr_sign($csr, null, $key, $validDays, $options, (int) hexdec(bin2hex(random_bytes(7))));
        if (!$certificate instanceof OpenSSLCertificate || !openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('OpenSSL could not sign or export the SP key pair.'); // @codeCoverageIgnore
        }

        return new SamlSpKeyPair($privateKeyPem, $certificatePem, SamlCertificate::fromPem($certificatePem)->notAfter);
    }
}
