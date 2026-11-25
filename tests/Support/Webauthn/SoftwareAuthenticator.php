<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Webauthn;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Nowo\AuthKitBundle\Webauthn\Base64Url;
use OpenSSLAsymmetricKey;
use RuntimeException;

use function array_key_exists;
use function chr;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function pack;
use function random_bytes;
use function str_pad;
use function str_repeat;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

/**
 * A software passkey for the tests (2.4.0): an ES256 key pair, a random credential id and a sign counter. It answers
 * the JSON options of AuthKit the way a browser's `PublicKeyCredential.toJSON()` does (attestation `none` or packed
 * self attestation), with knobs to break one check at a time (origin, RP id, user verification, counter, user handle).
 */
final class SoftwareAuthenticator
{
    public const FLAG_UP = 0x01;

    public const FLAG_UV = 0x04;

    public const FLAG_BE = 0x08;

    public const FLAG_BS = 0x10;

    public const FLAG_AT = 0x40;

    public readonly string $credentialId;

    public ?string $userHandle = null;

    private readonly OpenSSLAsymmetricKey $key;

    public function __construct(
        public int $counter = 0,
        public bool $counting = true,
        ?string $credentialId = null,
    ) {
        $this->credentialId = $credentialId ?? random_bytes(16);
        $key                = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('No EC key.');
        }
        $this->key = $key;
    }

    /**
     * The answer of `navigator.credentials.create()` to `$optionsJson`.
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, fmt?: string, type?: string, challenge?: string} $tweaks
     */
    public function register(string $optionsJson, string $origin = 'http://localhost', array $tweaks = []): string
    {
        $options          = $this->decode($optionsJson);
        $rp               = is_array($options['rp'] ?? null) ? $options['rp'] : [];
        $user             = is_array($options['user'] ?? null) ? $options['user'] : [];
        $this->userHandle = Base64Url::decode((string) ($user['id'] ?? ''));
        $clientData       = $this->clientData($tweaks['type'] ?? 'webauthn.create', $tweaks['challenge'] ?? (string) ($options['challenge'] ?? ''), $tweaks['origin'] ?? $origin);
        $flags            = $tweaks['flags'] ?? (self::FLAG_UP | self::FLAG_UV | self::FLAG_BE | self::FLAG_BS | self::FLAG_AT);
        $authData         = hash('sha256', $tweaks['rp_id'] ?? (string) ($rp['id'] ?? ''), true) . chr($flags) . pack('N', $this->counter)
            . str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->coseKey();
        $fmt       = $tweaks['fmt'] ?? 'none';
        $statement = MapObject::create();
        if ($fmt === 'packed') {
            $statement->add(TextStringObject::create('alg'), NegativeIntegerObject::create(-7));
            $statement->add(TextStringObject::create('sig'), ByteStringObject::create($this->sign($authData . hash('sha256', $clientData, true))));
        }
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create($fmt))
            ->add(TextStringObject::create('attStmt'), $statement)
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return $this->encode([
            'id'                      => Base64Url::encode($this->credentialId),
            'rawId'                   => Base64Url::encode($this->credentialId),
            'type'                    => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults'  => [],
            'response'                => [
                'clientDataJSON'    => Base64Url::encode($clientData),
                'attestationObject' => Base64Url::encode((string) $attestation),
                'transports'        => ['hybrid', 'internal'],
            ],
        ]);
    }

    /**
     * The answer of `navigator.credentials.get()` to `$optionsJson` (the counter moves first when it counts).
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, user_handle?: string|null, type?: string, challenge?: string, counter?: int} $tweaks
     */
    public function assert(string $optionsJson, string $origin = 'http://localhost', array $tweaks = []): string
    {
        $options = $this->decode($optionsJson);
        if ($this->counting) {
            ++$this->counter;
        }
        $clientData = $this->clientData($tweaks['type'] ?? 'webauthn.get', $tweaks['challenge'] ?? (string) ($options['challenge'] ?? ''), $tweaks['origin'] ?? $origin);
        $flags      = $tweaks['flags'] ?? (self::FLAG_UP | self::FLAG_UV | self::FLAG_BE | self::FLAG_BS);
        $authData   = hash('sha256', $tweaks['rp_id'] ?? (string) ($options['rpId'] ?? ''), true) . chr($flags) . pack('N', $tweaks['counter'] ?? $this->counter);
        $handle     = array_key_exists('user_handle', $tweaks) ? $tweaks['user_handle'] : $this->userHandle;

        return $this->encode([
            'id'                      => Base64Url::encode($this->credentialId),
            'rawId'                   => Base64Url::encode($this->credentialId),
            'type'                    => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults'  => [],
            'response'                => [
                'clientDataJSON'    => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authData),
                'signature'         => Base64Url::encode($this->sign($authData . hash('sha256', $clientData, true))),
                'userHandle'        => $handle !== null ? Base64Url::encode($handle) : null,
            ],
        ]);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return $this->encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $ec      = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : [];
        $x       = is_string($ec['x'] ?? null) ? $ec['x'] : '';
        $y       = is_string($ec['y'] ?? null) ? $ec['y'] : '';

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($x, 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($y, 32, "\0", STR_PAD_LEFT)));
    }

    private function sign(string $data): string
    {
        openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return (string) $signature;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
