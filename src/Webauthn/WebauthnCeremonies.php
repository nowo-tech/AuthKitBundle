<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use DateTimeImmutable;
use Nowo\AuthKitBundle\DependencyInjection\WebauthnConfiguration;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Webauthn\RelyingParty\WebAuthnRelyingParty;
use Nowo\AuthKitBundle\Webauthn\RelyingParty\WebAuthnRelyingPartyResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AttestationStatement\TPMAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

use function array_map;
use function hash;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The WebAuthn ceremonies of AuthKit (2.4.0) on top of `web-auth/webauthn-lib` (CBOR, COSE and attestation parsing,
 * signature and origin checks; nothing hand-rolled here).
 *
 * Challenges are 32 random bytes kept server-side in the user's session (bound to it), one per profile and ceremony,
 * valid for `webauthn.timeout_ms` plus 30 seconds. A challenge is taken out of the session before the response is
 * verified and claimed once more in the attempt limiter's store under its per-key lock, so two parallel requests of the
 * same session (session handlers that do not lock) cannot both use it (REQ-RUNTIME-003). Every ceremony requires user
 * verification, checks the exact origin and the RP id hash.
 *
 * 2.6.0: the RP id and the allowed origins come from {@see WebAuthnRelyingPartyProviderInterface}, asked again (and
 * validated) by every option and verification call ({@see WebAuthnRelyingPartyResolver}); an invalid relying party fails
 * closed ({@see WebauthnFailure::RELYING_PARTY}). A verification also refuses options issued for another RP id than the
 * current one ({@see WebauthnFailure::RP_ID_MISMATCH}).
 *
 * Stateless: the library objects (serializer, validators) are built per call and never kept (FrankenPHP worker).
 *
 * @internal
 */
final readonly class WebauthnCeremonies
{
    public const SESSION_KEY = '_nowo_auth_kit_webauthn';

    /** Largest client response accepted (bytes of JSON). */
    public const MAX_RESPONSE_BYTES = 65536;

    /** COSE algorithms offered, in order of preference: ES256, EdDSA (Ed25519), RS256. */
    public const ALGORITHMS = [-7, -8, -257];

    public function __construct(
        private ClockInterface $clock,
        private AuthKitAttemptLimiter $limiter,
        private WebAuthnRelyingPartyResolver $relyingParty,
    ) {
    }

    /**
     * Creation options for a new passkey of a user (stored in the session, returned for the browser).
     *
     * @param string $userHandle raw bytes of the opaque user handle
     * @param list<string> $excludeIds raw credential ids the user already has
     *
     * @throws WebauthnFailure `relying_party`
     */
    public function creationOptions(ProfileSettings $profile, Request $request, string $userHandle, string $name, string $displayName, array $excludeIds): string
    {
        $settings = $profile->webauthn;
        // WebAuthn Level 3 deprecates rp.name: the library serializes the RP id in its place.
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('', $this->relyingParty($profile, $request)->rpId),
            PublicKeyCredentialUserEntity::create($name, $userHandle, $displayName),
            random_bytes(32),
            array_map(PublicKeyCredentialParameters::createPk(...), self::ALGORITHMS),
            AuthenticatorSelectionCriteria::create(null, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED, AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED),
            ($settings['attestation'] ?? WebauthnConfiguration::ATTESTATION_NONE) === WebauthnConfiguration::ATTESTATION_DIRECT
                ? PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_DIRECT
                : PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $id), $excludeIds),
            $this->timeout($profile),
        );
        $json = $this->json($options);
        $this->remember($request, $profile, 'register', $json, $options->challenge);

        return $json;
    }

    /**
     * Request options of a sign-in (`login`: discoverable, no credential list) or a second step (`mfa`: the user's
     * passkeys), stored in the session and returned for the browser.
     *
     * @param list<array{id: string, transports: list<string>}> $allow raw ids and transports (empty: discoverable)
     *
     * @throws WebauthnFailure `relying_party`
     */
    public function requestOptions(ProfileSettings $profile, Request $request, string $ceremony, array $allow = []): string
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->relyingParty($profile, $request)->rpId,
            array_map(static fn (array $credential): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $credential['id'], $credential['transports']), $allow),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $this->timeout($profile),
        );
        $json = $this->json($options);
        $this->remember($request, $profile, $ceremony, $json, $options->challenge);

        return $json;
    }

    /**
     * Verifies a registration response against the session's creation options (the challenge is consumed first).
     * Returns the passkey and the RP id it was created for (2.6.0: the options' RP id, checked to be the current one).
     *
     * @throws WebauthnFailure
     *
     * @return array{0: CredentialRecord, 1: string}
     */
    public function verifyCreation(ProfileSettings $profile, Request $request, string $json): array
    {
        $credential = $this->parse($json);
        $response   = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new WebauthnFailure(WebauthnFailure::MALFORMED, 'Not a registration response.');
        }
        $options = $this->consume($request, $profile, 'register', PublicKeyCredentialCreationOptions::class);
        $party   = $this->current($profile, $request, $options->rp->id);
        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->factory($party)->creationCeremony())->check($response, $options, $party->rpId);
        } catch (Throwable $exception) {
            throw new WebauthnFailure(WebauthnFailure::INVALID, 'The registration response was refused: ' . $exception->getMessage(), $exception);
        }
        if (($profile->webauthn['attestation'] ?? WebauthnConfiguration::ATTESTATION_NONE) === WebauthnConfiguration::ATTESTATION_DIRECT && $record->attestationType === 'none') {
            throw new WebauthnFailure(WebauthnFailure::INVALID, 'webauthn.attestation is direct: an attestation statement is required.');
        }

        return [$record, $party->rpId];
    }

    /**
     * The parsed assertion of a sign-in or second step, with the session's request options. The body is parsed first
     * (a malformed body leaves the challenge in place: it is session-bound and the routes are rate limited), then the
     * challenge is consumed before the credential is looked up, so every lookup outcome costs the same challenge.
     *
     * @throws WebauthnFailure
     *
     * @return array{0: PublicKeyCredential, 1: AuthenticatorAssertionResponse, 2: PublicKeyCredentialRequestOptions}
     */
    public function assertion(ProfileSettings $profile, Request $request, string $ceremony, string $json): array
    {
        $credential = $this->parse($json);
        $response   = $credential->response;
        $options    = $this->consume($request, $profile, $ceremony, PublicKeyCredentialRequestOptions::class);
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new WebauthnFailure(WebauthnFailure::MALFORMED, 'Not an assertion response.');
        }

        return [$credential, $response, $options];
    }

    /**
     * Verifies an assertion against a stored passkey; returns the counter and backup state to record.
     *
     * @param string|null $userHandle raw user handle of the user identified before the ceremony (second step), else null
     *
     * @throws WebauthnFailure
     *
     * @return array{counter: int, backup: bool|null}
     */
    public function verifyAssertion(ProfileSettings $profile, Request $request, WebauthnCredentialRecord $record, AuthenticatorAssertionResponse $response, PublicKeyCredentialRequestOptions $options, ?string $userHandle): array
    {
        $party = $this->current($profile, $request, $options->rpId);
        // 2.6.0: a passkey created for another RP id never verifies under this one (null: created before 2.6.0, for the
        // RP id configured then; the RP id hash check below still applies).
        if ($record->rpId !== null && $record->rpId !== $party->rpId) {
            throw new WebauthnFailure(WebauthnFailure::RP_ID_MISMATCH, 'The passkey was created for another RP id.');
        }
        $stored = CredentialRecord::create(
            (string) Base64Url::decode($record->credentialId),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $record->transports,
            'none',
            EmptyTrustPath::create(),
            $record->aaguid !== null && Uuid::isValid($record->aaguid) ? Uuid::fromString($record->aaguid) : Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            (string) Base64Url::decode($record->publicKey),
            (string) Base64Url::decode($record->userHandle),
            $record->signCount,
            null,
            $record->backupEligible,
            $record->backupState,
            true,
        );
        try {
            $checked = AuthenticatorAssertionResponseValidator::create($this->factory($party)->requestCeremony())
                ->check($stored, $response, $options, $party->rpId, $userHandle);
        } catch (CounterException $exception) {
            throw new WebauthnFailure(WebauthnFailure::COUNTER, 'The sign counter did not increase (replay or cloned authenticator).', $exception);
        } catch (Throwable $exception) {
            throw new WebauthnFailure(WebauthnFailure::INVALID, 'The assertion was refused: ' . $exception->getMessage(), $exception);
        }

        return ['counter' => $checked->counter, 'backup' => $checked->backupStatus];
    }

    /**
     * The current RP id of the profile on this request ({@see WebAuthnRelyingPartyProviderInterface}, validated).
     *
     * @throws WebauthnFailure `relying_party`
     */
    public function rpId(ProfileSettings $profile, Request $request): string
    {
        return $this->relyingParty($profile, $request)->rpId;
    }

    /**
     * The validated relying party of the profile on this request (2.6.0), asked again on every call.
     *
     * @throws WebauthnFailure `relying_party`
     */
    public function relyingParty(ProfileSettings $profile, Request $request): WebAuthnRelyingParty
    {
        return $this->relyingParty->resolve($profile, $request);
    }

    /**
     * Seconds a challenge stays valid (browser timeout plus 30 seconds).
     */
    public function lifetime(ProfileSettings $profile): int
    {
        return intdiv($this->timeout($profile), 1000) + 30;
    }

    /**
     * @return int<1, max>
     */
    private function timeout(ProfileSettings $profile): int
    {
        $timeout = $profile->webauthn['timeout_ms'] ?? 60000;

        return is_int($timeout) && $timeout > 0 ? $timeout : 60000;
    }

    private function remember(Request $request, ProfileSettings $profile, string $ceremony, string $json, string $challenge): void
    {
        $session = $request->getSession();
        $state   = $session->get(self::SESSION_KEY);
        $state   = is_array($state) ? $state : [];
        // @igor-ignore - The request's own session (not an injected service).
        $state[$profile->name . '.' . $ceremony] = [
            'options'   => $this->withoutRpName($json),
            'challenge' => hash('sha256', $challenge),
            'expires'   => $this->now()->getTimestamp() + $this->lifetime($profile),
        ];
        $session->set(self::SESSION_KEY, $state);
    }

    /**
     * The options as kept in the session: without `rp.name` (the library fills it with the RP id for the browser, and
     * reading it back would set the field deprecated since web-auth/webauthn-lib 5.3).
     */
    private function withoutRpName(string $json): string
    {
        $options = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($options) && is_array($options['rp'] ?? null)) {
            unset($options['rp']['name']);
        }

        return json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Takes the ceremony's options out of the session and claims its challenge once.
     *
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     *
     * @param class-string<T> $class
     *
     * @throws WebauthnFailure
     *
     * @return T
     */
    private function consume(Request $request, ProfileSettings $profile, string $ceremony, string $class): object
    {
        $session = $request->getSession();
        $state   = $session->get(self::SESSION_KEY);
        $key     = $profile->name . '.' . $ceremony;
        $entry   = is_array($state) ? ($state[$key] ?? null) : null;
        if (is_array($state)) {
            unset($state[$key]);
            // @igor-ignore - The request's own session (not an injected service).
            $session->set(self::SESSION_KEY, $state);
        }
        if (!is_array($entry) || !is_string($entry['options'] ?? null) || !is_string($entry['challenge'] ?? null) || !is_int($entry['expires'] ?? null)
            || $entry['expires'] < $this->now()->getTimestamp()) {
            throw new WebauthnFailure(WebauthnFailure::CHALLENGE, 'No live WebAuthn challenge in this session.');
        }
        // Single use across parallel requests of one session: the claim is a check-and-set under the limiter's lock.
        if (!$this->limiter->consume('webauthn:challenge:' . $entry['challenge'], 1, $this->lifetime($profile) + 60)) {
            throw new WebauthnFailure(WebauthnFailure::CHALLENGE, 'The WebAuthn challenge was already used.');
        }
        /** @var T $options */
        $options = $this->serializer()->deserialize($entry['options'], $class, 'json');

        return $options;
    }

    /**
     * @throws WebauthnFailure
     */
    private function parse(string $json): PublicKeyCredential
    {
        if ($json === '' || strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new WebauthnFailure(WebauthnFailure::MALFORMED, 'Empty or oversized WebAuthn response.');
        }
        try {
            $credential = $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (Throwable $exception) {
            throw new WebauthnFailure(WebauthnFailure::MALFORMED, 'The WebAuthn response cannot be parsed.', $exception);
        }

        return $credential;
    }

    private function json(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS                        => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ]);
    }

    private function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationSupports()))->create();
    }

    /**
     * Every statement format the library verifies: a browser may keep a self attestation (packed) even when `none` was
     * asked, so it is checked, never refused for its format. `attestation: direct` only refuses a `none` statement.
     */
    private function attestationSupports(): AttestationStatementSupportManager
    {
        return new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
            PackedAttestationStatementSupport::create($this->algorithms()),
            FidoU2FAttestationStatementSupport::create(),
            AndroidKeyAttestationStatementSupport::create(),
            AppleAttestationStatementSupport::create(),
            TPMAttestationStatementSupport::create($this->clock),
        ]);
    }

    /**
     * The current relying party, which must still have the RP id the ceremony's options were issued for (2.6.0): options
     * of a previous RP id are refused ({@see WebauthnFailure::RP_ID_MISMATCH}), never verified under the new one.
     *
     * @throws WebauthnFailure `relying_party` or `rp_id_mismatch`
     */
    private function current(ProfileSettings $profile, Request $request, ?string $issuedFor): WebAuthnRelyingParty
    {
        $party = $this->relyingParty($profile, $request);
        if ($issuedFor !== $party->rpId) {
            throw new WebauthnFailure(WebauthnFailure::RP_ID_MISMATCH, 'The ceremony was started for another RP id.');
        }

        return $party;
    }

    private function factory(WebAuthnRelyingParty $party): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAlgorithmManager($this->algorithms());
        $factory->setAttestationStatementSupportManager($this->attestationSupports());
        $factory->setAllowedOrigins($party->origins);

        return $factory;
    }

    /**
     * The exact origins a ceremony may come from ({@see WebAuthnRelyingPartyProviderInterface}, validated; the default
     * is `webauthn.allowed_origins`, else exactly `https://<rp id>`, audit ZBX-M1: never the request's `Host`).
     *
     * @throws WebauthnFailure `relying_party`
     *
     * @return list<string>
     */
    public function allowedOrigins(ProfileSettings $profile, Request $request): array
    {
        return $this->relyingParty($profile, $request)->origins;
    }

    private function algorithms(): Manager
    {
        return Manager::create()->add(ES256::create(), Ed25519::create(), RS256::create());
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
