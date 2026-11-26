<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Port\Internal\UserLookupInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialAddedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function hash;
use function hash_hmac;
use function is_array;
use function is_int;
use function mb_substr;
use function preg_replace;
use function random_bytes;
use function sprintf;
use function trim;

/**
 * Public API of the passkeys (2.4.0): list, register (options + verification), passwordless sign-in, second step,
 * rename, remove, personal data. Users are referenced by their MFA owner key ({@see MfaOwnerResolver}); the opaque
 * WebAuthn user handle is an HMAC of it (`webauthn.hash_secret`), never personal data.
 *
 * Deny by default: an unknown credential, another profile's credential and another user's credential fail in the same
 * way ({@see WebauthnFailure}; the controllers answer every failure with one generic message). Registration and
 * removal run under a per-owner lock (symfony/lock), so `webauthn.max_credentials` and the last-factor guard hold under
 * parallel requests; the sign counter moves by a conditional UPDATE ({@see WebauthnStore::recordUse()}).
 *
 * Stateless (worker safe): every call resolves the profile, the owner and the passkeys again; events follow the write.
 */
final readonly class WebauthnManager
{
    public const LABEL_MAX_LENGTH = 100;

    /** Seconds a per-owner lock survives if the process dies before releasing it. */
    private const LOCK_TTL = 10.0;

    public function __construct(
        private ProfileRegistry $profiles,
        private WebauthnStore $store,
        private WebauthnCeremonies $ceremonies,
        private MfaOwnerResolver $owners,
        private UserLookupInterface $users,
        private ClockInterface $clock,
        private AuthKitAttemptLimiter $limiter,
        private ?LockFactory $locks = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The WebAuthn-enabled profile of a user (by its class), or null.
     */
    public function profileFor(UserInterface $user): ?ProfileSettings
    {
        $profile = $this->profiles->resolveForObject($user);

        return $profile instanceof ProfileSettings && $profile->webauthnEnabled() ? $profile : null;
    }

    /**
     * The user's passkeys in its profile, oldest first (empty without a profile or a usable owner id).
     *
     * @return list<WebauthnPasskey>
     */
    public function passkeys(UserInterface $user): array
    {
        return array_map(static fn (WebauthnCredentialRecord $record): WebauthnPasskey => $record->passkey(), $this->records($user));
    }

    /**
     * Whether the user has at least one passkey that counts as a second factor (`webauthn.mfa` with `mfa.enabled`).
     */
    public function hasSecondFactor(UserInterface $user): bool
    {
        $profile = $this->profileFor($user);

        return $profile instanceof ProfileSettings && $profile->webauthnMfa() && $this->records($user) !== [];
    }

    /**
     * What the account page hands to the browser's Signal API (`PublicKeyCredential.signalAllAcceptedCredentials()`,
     * Chrome Modern Web Guidance "passkey-management"): the RP id, the user handle and the credential ids the server
     * still accepts, all unpadded base64url, so a password manager drops the passkeys removed here. The signed-in
     * user's own data only.
     *
     * @throws WebauthnFailure `user`
     *
     * @return array{rpId: string, userId: string, allAcceptedCredentialIds: list<string>}
     */
    public function acceptedCredentials(UserInterface $user, Request $request): array
    {
        [$profile, $owner] = $this->context($user);

        return [
            'rpId'                     => $this->ceremonies->rpId($profile, $request),
            'userId'                   => Base64Url::encode($this->userHandle($profile, $owner)),
            'allAcceptedCredentialIds' => array_map(static fn (WebauthnCredentialRecord $record): string => $record->credentialId, $this->store->credentialsOf($profile->name, $owner)),
        ];
    }

    /**
     * Creation options for a new passkey of the signed-in user (JSON for `navigator.credentials.create()`).
     *
     * @throws WebauthnFailure `limit`, `rate_limited` or `user`
     */
    public function registrationOptions(UserInterface $user, Request $request): string
    {
        [$profile, $owner] = $this->context($user);
        $register          = $this->limit($profile, 'register', 10, 900);
        if (!$this->limiter->consume('webauthn:register:' . $profile->name . ':' . $owner->key(), $register[0], $register[1])) {
            throw $this->failed(new WebauthnFailure(WebauthnFailure::RATE_LIMITED), $profile, 'register', $owner);
        }
        $records = $this->store->credentialsOf($profile->name, $owner);
        if (count($records) >= $this->max($profile)) {
            throw $this->failed(new WebauthnFailure(WebauthnFailure::LIMIT), $profile, 'register', $owner);
        }

        return $this->ceremonies->creationOptions(
            $profile,
            $request,
            $this->userHandle($profile, $owner),
            $user->getUserIdentifier(),
            $user->getUserIdentifier(),
            array_map(static fn (WebauthnCredentialRecord $record): string => (string) Base64Url::decode($record->credentialId), $records),
        );
    }

    /**
     * Verifies a registration response and stores the passkey (label trimmed and capped; empty = "Passkey").
     *
     * @throws WebauthnFailure
     */
    public function register(UserInterface $user, Request $request, string $json, string $label): WebauthnPasskey
    {
        [$profile, $owner] = $this->context($user);
        try {
            $credential = $this->ceremonies->verifyCreation($profile, $request, $json);
        } catch (WebauthnFailure $failure) {
            throw $this->failed($failure, $profile, 'register', $owner);
        }
        $handle = $this->userHandle($profile, $owner);
        if ($credential->userHandle !== $handle) {
            // The options of this session were built for another owner (a session that changed user in between).
            throw $this->failed(new WebauthnFailure(WebauthnFailure::INVALID, 'The registration belongs to another account.'), $profile, 'register', $owner);
        }
        $publicId = bin2hex(random_bytes(16));
        $label    = self::label($label);
        $now      = $this->now();
        $outcome  = $this->locked($profile, $owner, function () use ($profile, $owner, $credential, $handle, $publicId, $label, $now): string {
            if (count($this->store->credentialsOf($profile->name, $owner)) >= $this->max($profile)) {
                return WebauthnFailure::LIMIT;
            }
            $stored = $this->store->insert(
                $publicId,
                $profile->name,
                $owner,
                $credential->publicKeyCredentialId,
                Base64Url::encode($handle),
                Base64Url::encode($credential->credentialPublicKey),
                $credential->counter,
                array_values($credential->transports),
                $credential->aaguid->toRfc4122(),
                $credential->backupEligible,
                $credential->backupStatus,
                $label,
                $now,
            );

            return $stored ? '' : WebauthnFailure::DUPLICATE;
        });
        if ($outcome !== '') {
            throw $this->failed(new WebauthnFailure($outcome), $profile, 'register', $owner);
        }
        $this->dispatch(new WebauthnCredentialAddedEvent($profile->name, $owner->type, $owner->id, $publicId, $label, $credential->backupStatus === true));
        $this->logger->info('Passkey registered.', ['profile' => $profile->name, 'owner' => $owner->key(), 'passkey' => $publicId]);

        return new WebauthnPasskey($publicId, $label, $now, null, array_values($credential->transports), $credential->backupStatus === true, $credential->aaguid->toRfc4122());
    }

    /**
     * Request options of a passwordless sign-in (discoverable credentials, conditional UI), rate limited per client IP.
     *
     * @throws WebauthnFailure `rate_limited`
     */
    public function loginOptions(ProfileSettings $profile, Request $request): string
    {
        $this->rateLimit($profile, 'options', 30, 300, $request, 'login');

        return $this->ceremonies->requestOptions($profile, $request, 'login');
    }

    /**
     * Verifies a passwordless assertion and returns the user to sign in (the caller signs it in).
     *
     * @throws WebauthnFailure every refusal, with the same meaning for the browser
     */
    public function login(ProfileSettings $profile, Request $request, string $json): UserInterface
    {
        $this->rateLimit($profile, 'assert', 20, 300, $request, 'login');
        $record = $this->assert($profile, $request, 'login', $json, null);
        $user   = $this->loadUser($profile, $record);
        if (!$user instanceof UserInterface) {
            throw $this->failed(new WebauthnFailure(WebauthnFailure::USER), $profile, 'login', null, $record);
        }
        $this->release($profile, $request);
        $this->dispatch(new WebauthnCredentialUsedEvent($profile->name, $record->userType, $record->userId, $record->publicId, WebauthnCredentialUsedEvent::CONTEXT_LOGIN));

        return $user;
    }

    /**
     * Request options of the second step for the pending user (its passkeys only), rate limited per client IP.
     *
     * @throws WebauthnFailure `rate_limited` or `user`
     */
    public function mfaOptions(UserInterface $user, Request $request): string
    {
        [$profile] = $this->context($user);
        $this->rateLimit($profile, 'options', 30, 300, $request, 'mfa');
        $allow = array_map(static fn (WebauthnCredentialRecord $record): array => ['id' => (string) Base64Url::decode($record->credentialId), 'transports' => $record->transports], $this->records($user));

        return $this->ceremonies->requestOptions($profile, $request, 'mfa', $allow);
    }

    /**
     * Verifies the second step of the pending user: the passkey must be one of the user's.
     *
     * @throws WebauthnFailure
     */
    public function verifyMfa(UserInterface $user, Request $request, string $json): WebauthnPasskey
    {
        [$profile, $owner] = $this->context($user);
        $this->rateLimit($profile, 'assert', 20, 300, $request, 'mfa');
        $record = $this->assert($profile, $request, 'mfa', $json, $owner);
        $this->release($profile, $request);
        $this->dispatch(new WebauthnCredentialUsedEvent($profile->name, $record->userType, $record->userId, $record->publicId, WebauthnCredentialUsedEvent::CONTEXT_MFA));

        return $record->passkey();
    }

    /**
     * Renames one of the user's passkeys; false when it is unknown (or another account's: the same answer).
     */
    public function rename(UserInterface $user, string $publicId, string $label): bool
    {
        [$profile, $owner] = $this->context($user);
        $record            = $this->store->findForOwner($profile->name, $owner, $publicId);

        return $record instanceof WebauthnCredentialRecord && $this->store->rename($record, self::label($label));
    }

    /**
     * Removes one of the user's passkeys. `$keepOne`: refuse to remove the last one (the caller's last-factor guard,
     * e.g. a required second factor without TOTP).
     */
    public function remove(UserInterface $user, string $publicId, bool $keepOne = false): WebauthnRemoval
    {
        [$profile, $owner] = $this->context($user);
        $removed           = $this->locked($profile, $owner, function () use ($profile, $owner, $publicId, $keepOne): WebauthnRemoval|WebauthnCredentialRecord {
            $record = $this->store->findForOwner($profile->name, $owner, $publicId);
            if (!$record instanceof WebauthnCredentialRecord) {
                return WebauthnRemoval::Unknown;
            }
            if ($keepOne && count($this->store->credentialsOf($profile->name, $owner)) <= 1) {
                return WebauthnRemoval::LastFactor;
            }

            return $this->store->delete($record) ? $record : WebauthnRemoval::Unknown;
        });
        if ($removed instanceof WebauthnRemoval) {
            return $removed;
        }
        $this->dispatch(new WebauthnCredentialRemovedEvent($profile->name, $owner->type, $owner->id, $removed->publicId, $removed->label, WebauthnCredentialRemovedEvent::REASON_USER));
        $this->logger->info('Passkey removed.', ['profile' => $profile->name, 'owner' => $owner->key(), 'passkey' => $removed->publicId]);

        return WebauthnRemoval::Removed;
    }

    /**
     * Deletes every passkey of the user, in every profile (audit ZBX-L3): call it when the account is deleted without
     * UserKit's erasure (which runs {@see PersonalData\WebauthnPersonalDataEraser}); {@see MfaManager::forgetUser()}
     * calls it. Passkey rows reference the user by owner id without a foreign key: a passkey left behind would sign in
     * a later account that gets the same id. One {@see WebauthnCredentialRemovedEvent} (`reason: erasure`) per passkey.
     * Returns how many were deleted.
     */
    public function forgetUser(UserInterface $user): int
    {
        return (new WebauthnErasure($this->store, $this->owners, $this->dispatcher))->forgetUser($user);
    }

    /**
     * A label as stored: control characters removed, white space trimmed, at most {@see self::LABEL_MAX_LENGTH}
     * characters; "Passkey" when nothing is left.
     */
    public static function label(string $label): string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $label));
        $clean = trim(mb_substr($clean, 0, self::LABEL_MAX_LENGTH, 'UTF-8'));

        return $clean !== '' ? $clean : 'Passkey';
    }

    /**
     * The verified passkey of an assertion: the challenge is consumed, then the credential is looked up (in the
     * profile, and among the expected owner's passkeys for a second step), then the signature, user verification,
     * origin and counter are checked and the counter recorded.
     *
     * @throws WebauthnFailure
     */
    private function assert(ProfileSettings $profile, Request $request, string $context, string $json, ?MfaOwner $owner): WebauthnCredentialRecord
    {
        $record = null;
        try {
            [$credential, $response, $options] = $this->ceremonies->assertion($profile, $request, $context, $json);
            $record                            = $this->store->findByCredentialId($profile->name, $credential->rawId);
            if (!$record instanceof WebauthnCredentialRecord || ($owner instanceof MfaOwner && ($record->userType !== $owner->type || $record->userId !== $owner->id))) {
                // Deny by default: unknown, another profile's or another user's credential — one answer.
                $record = null;

                throw new WebauthnFailure(WebauthnFailure::UNKNOWN_CREDENTIAL);
            }
            // The handle stored with the passkey (audit ZBX-L2): the row is already bound to the pending owner above, and
            // a rotated webauthn.hash_secret must not lock out the passkeys registered before it.
            $expected = $owner instanceof MfaOwner ? (string) Base64Url::decode($record->userHandle) : null;
            $checked  = $this->ceremonies->verifyAssertion($profile, $request, $record, $response, $options, $expected);
            if (!$this->store->recordUse($record, $checked['counter'], $checked['backup'], $this->now())) {
                throw new WebauthnFailure(WebauthnFailure::COUNTER, 'The sign counter was already moved (parallel use, replay or clone).');
            }
        } catch (WebauthnFailure $failure) {
            if ($failure->reason === WebauthnFailure::COUNTER) {
                $this->logger->warning('Passkey sign counter did not increase: a replay, a parallel use or a cloned authenticator.', ['profile' => $profile->name, 'passkey' => $record?->publicId]);
            }

            throw $this->failed($failure, $profile, $context, $owner, $record);
        }

        return $record;
    }

    /**
     * The user of a passkey, found again by its owner id and checked to resolve to exactly that owner, in the profile's
     * user class (null when it is gone, changed class or resolves elsewhere).
     */
    private function loadUser(ProfileSettings $profile, WebauthnCredentialRecord $record): ?UserInterface
    {
        $user = $this->users->findById($profile, $record->userId) ?? $this->users->findByIdentifier($profile, $record->userId);
        if (!$user instanceof $profile->userClass) {
            return null;
        }
        $owner = $this->owners->resolve($user);

        return $owner instanceof MfaOwner && $owner->type === $record->userType && $owner->id === $record->userId ? $user : null;
    }

    /**
     * @return list<WebauthnCredentialRecord>
     */
    private function records(UserInterface $user): array
    {
        $profile = $this->profileFor($user);
        $owner   = $this->owners->resolve($user);

        return $profile instanceof ProfileSettings && $owner instanceof MfaOwner ? $this->store->credentialsOf($profile->name, $owner) : [];
    }

    /**
     * @throws WebauthnFailure `user` when the user has no WebAuthn profile or no usable owner id
     *
     * @return array{0: ProfileSettings, 1: MfaOwner}
     */
    private function context(UserInterface $user): array
    {
        $profile = $this->profileFor($user) ?? throw new WebauthnFailure(WebauthnFailure::USER, sprintf('No AuthKit profile with webauthn enabled for %s.', $user::class));
        $owner   = $this->owners->resolve($user) ?? throw new WebauthnFailure(WebauthnFailure::USER, 'This user cannot have passkeys: its identifier is not a usable owner id.');

        return [$profile, $owner];
    }

    /**
     * Raw bytes of the opaque user handle of an owner in a profile (32 bytes, HMAC-SHA-256).
     */
    private function userHandle(ProfileSettings $profile, MfaOwner $owner): string
    {
        return hash_hmac('sha256', 'auth_kit_webauthn_user_handle|' . $profile->name . '|' . $owner->key(), (string) ($profile->webauthn['hash_secret'] ?? ''), true);
    }

    /**
     * @throws WebauthnFailure `rate_limited`
     */
    private function rateLimit(ProfileSettings $profile, string $kind, int $limit, int $window, Request $request, string $context): void
    {
        [$max, $seconds] = $this->limit($profile, $kind, $limit, $window);
        if (!$this->limiter->consume($this->ipKey($profile, $kind, $request), $max, $seconds)) {
            throw $this->failed(new WebauthnFailure(WebauthnFailure::RATE_LIMITED), $profile, $context);
        }
    }

    /**
     * A successful assertion gives its per-IP slot back (only failures count against a busy shared address).
     */
    private function release(ProfileSettings $profile, Request $request): void
    {
        $this->limiter->release($this->ipKey($profile, 'assert', $request), $this->limit($profile, 'assert', 20, 300)[1]);
    }

    private function ipKey(ProfileSettings $profile, string $kind, Request $request): string
    {
        return 'webauthn:' . $kind . ':' . $profile->name . ':' . ($request->getClientIp() ?? 'unknown');
    }

    /**
     * @return array{0: int, 1: int} limit, window
     */
    private function limit(ProfileSettings $profile, string $kind, int $limit, int $window): array
    {
        $limits = is_array($profile->webauthn['rate_limit'] ?? null) ? $profile->webauthn['rate_limit'] : [];
        $node   = is_array($limits[$kind] ?? null) ? $limits[$kind] : [];

        return [is_int($node['limit'] ?? null) ? $node['limit'] : $limit, is_int($node['window'] ?? null) ? $node['window'] : $window];
    }

    private function max(ProfileSettings $profile): int
    {
        $max = $profile->webauthn['max_credentials'] ?? 10;

        return is_int($max) ? $max : 10;
    }

    /**
     * Runs `$work` under the owner's lock (count-then-write rules: the passkey limit, the last-factor guard).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(ProfileSettings $profile, MfaOwner $owner, callable $work): mixed
    {
        if (!$this->locks instanceof LockFactory) {
            // WebauthnLockRequiredPass refuses to build without a lock factory; a hand-built manager is unguarded.
            return $work();
        }
        $lock = $this->locks->createLock('auth_kit_webauthn_owner_' . hash('sha256', $profile->name . '|' . $owner->key()), self::LOCK_TTL);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private function failed(WebauthnFailure $failure, ProfileSettings $profile, string $context, ?MfaOwner $owner = null, ?WebauthnCredentialRecord $record = null): WebauthnFailure
    {
        $this->dispatch(new WebauthnAuthenticationFailedEvent(
            $profile->name,
            $context,
            $failure->reason,
            $record->userType ?? $owner->type ?? '',
            $record->userId ?? $owner->id ?? '',
            $record->publicId ?? '',
        ));

        return $failure;
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
