<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use DateTimeImmutable;
use LogicException;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengeFailedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaDisabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaEnabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLockedOutEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodesRegeneratedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodeUsedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaResetByAdminEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Mfa\Totp\Totp;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\UserKitBundle\Sessions\Model\RevocationReason;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_intersect;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function sprintf;

/**
 * Public API of the second factor (1.34): status, enrolment, code checks (rate limits, lockout, replay), disable,
 * recovery codes, trusted devices, admin reset, requirement and grace. Every write dispatches its audit event after
 * the write. UserKit sessions (optional): disabling / re-enrolling signs the user's other sessions out and an admin
 * reset signs every session out, both with the remember-me epoch (D-M4). Stateless (worker safe).
 */
final readonly class MfaManager
{
    /**
     * @param ContainerInterface|null $policies `mfa.policy` services by profile name
     */
    public function __construct(
        private ProfileRegistry $profiles,
        private MfaStore $store,
        private MfaOwnerResolver $owners,
        private ClockInterface $clock,
        private AuthKitAttemptLimiter $limiter,
        private ?RoleHierarchyInterface $roleHierarchy = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ContainerInterface $policies = null,
        private ?UserSessionManager $sessions = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * MFA-enabled profile of a user (by its class), or null when the user cannot have MFA.
     */
    public function profileFor(UserInterface $user): ?ProfileSettings
    {
        $profile = $this->profiles->resolveForObject($user);

        return $profile instanceof ProfileSettings && $profile->mfaEnabled() ? $profile : null;
    }

    /**
     * An MFA-enabled profile by name.
     *
     * @throws LogicException when the profile does not exist or has MFA off
     */
    public function profileNamed(string $name): ProfileSettings
    {
        $profile = $this->profiles->getByName($name);
        if (!$profile->mfaEnabled()) {
            throw new LogicException(sprintf('The AuthKit profile "%s" has mfa.enabled: false.', $name));
        }

        return $profile;
    }

    public function isEnabled(UserInterface $user): bool
    {
        return $this->credential($user)?->isEnabled() === true;
    }

    public function status(UserInterface $user): ?MfaStatus
    {
        $profile = $this->profileFor($user);
        if (!$profile instanceof ProfileSettings) {
            return null;
        }
        $credential = $this->credential($user);
        $enabled    = $credential instanceof MfaCredential && $credential->isEnabled() ? $credential : null;
        $required   = $this->requirement($user) === MfaRequirement::Required;

        return new MfaStatus(
            $profile->name,
            $enabled instanceof MfaCredential,
            $enabled?->getEnabledAt(),
            $enabled instanceof MfaCredential ? $this->store->unusedRecoveryCodes($enabled) : 0,
            $enabled instanceof MfaCredential ? $this->store->devices($enabled, $this->now()) : [],
            $required,
            $required && !$enabled instanceof MfaCredential ? $this->graceEndsAt($profile, $credential) : null,
            (int) ($profile->mfa['recovery_codes']['low_warning'] ?? 3),
        );
    }

    /**
     * Whether the user must have MFA: `mfa.required_roles` (role hierarchy aware), then the `mfa.policy` service.
     */
    public function requirement(UserInterface $user): MfaRequirement
    {
        $profile = $this->profileFor($user);
        if (!$profile instanceof ProfileSettings) {
            return MfaRequirement::Optional;
        }
        $required = array_values(array_filter(is_array($profile->mfa['required_roles'] ?? null) ? $profile->mfa['required_roles'] : [], is_string(...)));
        if ($required !== []) {
            $roles = $this->roleHierarchy?->getReachableRoleNames($user->getRoles()) ?? $user->getRoles();
            if (array_intersect($required, $roles) !== []) {
                return MfaRequirement::Required;
            }
        }
        if ($this->policies?->has($profile->name) === true) {
            $policy = $this->policies->get($profile->name);
            if ($policy instanceof MfaPolicyInterface) {
                return $policy->requirement($user, $profile->name);
            }
        }

        return MfaRequirement::Optional;
    }

    /**
     * A required user without MFA whose grace period is over must enrol before signing in. Starts the grace period at
     * the first such check (the first sign-in after the requirement appeared).
     */
    public function mustEnrolNow(UserInterface $user): bool
    {
        $profile = $this->profileFor($user);
        $owner   = $this->owners->resolve($user);
        if (!$profile instanceof ProfileSettings || !$owner instanceof MfaOwner || $this->requirement($user) !== MfaRequirement::Required) {
            return false;
        }
        $credential = $this->store->credentialOrNew($profile->name, $owner, $this->now());
        if ($credential->isEnabled()) {
            return false;
        }
        if (!$credential->getRequiredSince() instanceof DateTimeImmutable) {
            $credential->markRequiredSince($this->now(), $this->now());
            $this->store->save($credential);
        }

        return !$this->graceEndsAt($profile, $credential) instanceof DateTimeImmutable;
    }

    /**
     * A fresh secret for an enrolment (kept in the session by the caller until confirmed, never stored before).
     */
    public function newSecret(): string
    {
        return Totp::generateSecret();
    }

    /**
     * The `otpauth://` URI of an enrolment QR code. Never log, flash or cache it.
     */
    public function provisioningUri(UserInterface $user, string $secret): string
    {
        $profile = $this->requireProfile($user);
        $issuer  = (string) ($profile->mfa['issuer'] ?? 'App');

        return $this->totp($profile)->provisioningUri($issuer, $user->getUserIdentifier(), $secret);
    }

    /**
     * Confirms an enrolment: the code must match `$secret`; MFA is turned on (replacing a previous secret and its
     * trusted devices), recovery codes are generated and returned once (display form). Null when the code is wrong.
     *
     * @return list<string>|null
     */
    public function enable(UserInterface $user, string $secret, string $code, ?Request $request = null): ?array
    {
        $profile = $this->requireProfile($user);
        $owner   = $this->requireOwner($user);
        $step    = $this->totp($profile)->verify($secret, $code, $this->now()->getTimestamp());
        if ($step === null) {
            return null;
        }
        $credential = $this->store->credentialOrNew($profile->name, $owner, $this->now());
        $reEnrolled = $credential->isEnabled();
        // @igor-ignore - A Doctrine entity of this request, not a shared service.
        $credential->enable($secret, $step, $this->now());
        $this->store->save($credential);
        $codes = $this->newRecoveryCodes($profile, $credential);
        if ($reEnrolled) {
            $this->dropDevices($profile, $user, $credential, 're_enrolled');
            $this->signOutOthers($user, $request);
        }
        $this->dispatch(new MfaEnabledEvent($profile->name, $user));
        $this->logger->info('MFA enabled.', ['profile' => $profile->name, 'owner' => $owner->key()]);

        return $codes;
    }

    /**
     * Checks a code (TOTP, or a recovery code when it has the recovery format): rate limits per account and client
     * IP, replay protection, single-use recovery codes. Failures are counted and audited; reaching the account limit
     * dispatches {@see MfaLockedOutEvent}.
     *
     * @param string $context `challenge` (sign-in) or `step_up` (account page)
     */
    public function check(UserInterface $user, string $code, ?string $clientIp, string $context = 'challenge'): MfaCheck
    {
        $profile    = $this->requireProfile($user);
        $credential = $this->credential($user);
        $secret     = $credential?->getTotpSecret();
        if (!$credential instanceof MfaCredential || !$credential->isEnabled() || $secret === null) {
            return MfaCheck::Invalid;
        }
        $limits     = $profile->mfa['rate_limit'] ?? [];
        $accountKey = 'mfa:account:' . $profile->name . ':' . $credential->owner()->key();
        $ipKey      = 'mfa:ip:' . ($clientIp ?? 'unknown');
        $account    = $this->limit($limits, 'account', 10, 900);
        $ip         = $this->limit($limits, 'ip', 30, 900);
        // DD-M1: one attempt is consumed atomically (under the limiter lock) BEFORE the code is verified, so concurrent
        // guesses cannot all pass a check-then-act window.
        if (!$this->limiter->consume($accountKey, $account[0], $account[1]) || !$this->limiter->consume($ipKey, $ip[0], $ip[1])) {
            return MfaCheck::LockedOut;
        }
        $recovery = RecoveryCodes::normalize($code);
        $kind     = $recovery !== null ? 'recovery' : 'totp';
        $result   = $kind === 'recovery'
            ? $this->checkRecovery($profile, $user, $credential, (string) $recovery)
            : $this->checkTotp($profile, $credential, $secret, $code);
        if ($result->isValid()) {
            $this->limiter->reset($accountKey);
            // DD-I7: the per-IP key counts wrong codes only (a busy shared NAT is not locked out by valid sign-ins).
            $this->limiter->release($ipKey, $ip[1]);

            return $result;
        }
        $this->dispatch(new MfaChallengeFailedEvent($profile->name, $user, $kind, $context));
        if ($this->limiter->count($accountKey) === $account[0]) {
            $this->dispatch(new MfaLockedOutEvent($profile->name, $user));
            $this->logger->warning('MFA locked out after too many wrong codes.', ['profile' => $profile->name, 'owner' => $credential->owner()->key()]);
        }

        return MfaCheck::Invalid;
    }

    /**
     * Turns MFA off (the caller checked a current code): secret, recovery codes and trusted devices go, the user's
     * other sessions are signed out with the remember-me epoch (UserKit).
     */
    public function disable(UserInterface $user, ?Request $request = null): void
    {
        $profile    = $this->requireProfile($user);
        $credential = $this->credential($user);
        if (!$credential instanceof MfaCredential || !$credential->isEnabled()) {
            return;
        }
        $this->clear($profile, $user, $credential, 'disabled');
        $this->signOutOthers($user, $request);
        $this->dispatch(new MfaDisabledEvent($profile->name, $user));
        $this->logger->info('MFA disabled.', ['profile' => $profile->name, 'owner' => $credential->owner()->key()]);
    }

    /**
     * Administrator reset (lost device): clears the factor without revealing it, signs every session of the user out
     * with the remember-me epoch, and makes a required user enrol at the next sign-in (no grace, D-M5).
     */
    public function reset(UserInterface $user, UserInterface $actor): bool
    {
        $profile    = $this->requireProfile($user);
        $credential = $this->credential($user);
        if (!$credential instanceof MfaCredential || !$credential->isEnabled()) {
            return false;
        }
        $this->clear($profile, $user, $credential, 'reset', $actor->getUserIdentifier());
        // Grace 0 after a reset: the required user enrols at the next sign-in.
        $credential->markRequiredSince(new DateTimeImmutable('@0'), $this->now());
        $this->store->save($credential);
        $this->sessions?->revokeAll($user, $actor, RevocationReason::Admin);
        $this->dispatch(new MfaResetByAdminEvent($profile->name, $user, $actor));
        $this->logger->warning('MFA reset by an administrator.', ['profile' => $profile->name, 'owner' => $credential->owner()->key(), 'actor' => $actor->getUserIdentifier()]);

        return true;
    }

    /**
     * New recovery codes (the caller checked a current code), returned once in display form.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(UserInterface $user): array
    {
        $profile    = $this->requireProfile($user);
        $credential = $this->credential($user);
        if (!$credential instanceof MfaCredential || !$credential->isEnabled()) {
            return [];
        }
        $codes = $this->newRecoveryCodes($profile, $credential);
        $this->dispatch(new MfaRecoveryCodesRegeneratedEvent($profile->name, $user));

        return $codes;
    }

    /**
     * Forgets trusted devices of the user: one (`$publicId`) or all. Returns how many were forgotten. With reason
     * `user` and UserKit device ids, the sessions of those devices are signed out too (the caller's own session, from
     * `$request`, is kept).
     */
    public function forgetDevices(UserInterface $user, string $reason = 'user', ?string $publicId = null, ?Request $request = null): int
    {
        $profile    = $this->profileFor($user);
        $credential = $this->credential($user);
        if (!$profile instanceof ProfileSettings || !$credential instanceof MfaCredential) {
            return 0;
        }

        return $this->dropDevices($profile, $user, $credential, $reason, $publicId, $request);
    }

    /**
     * Deletes everything MFA knows about a user (account removal / personal data erasure).
     */
    public function forgetUser(UserInterface $user): void
    {
        $credential = $this->credential($user);
        if ($credential instanceof MfaCredential) {
            $this->store->delete($credential);
        }
    }

    /**
     * Personal-data erasure (1.36): deletes the user's TOTP secret, recovery codes and trusted devices in **every**
     * profile, without flushing (inside UserKit's erasure transaction). Returns whether there was anything to delete.
     *
     * Rows are found by the owner reference (user class and id). When that id is the user identifier (an e-mail
     * natural key, or a user that is not a Doctrine entity), a host identity eraser that ran first has already changed
     * it: `$originalIdentifier` (UserKit's `ErasureRequest::identifier()`, captured before any eraser ran) is then looked
     * up as well. It is never used for surrogate ids, so it cannot reach another user's rows.
     */
    public function eraseUser(UserInterface $user, ?string $originalIdentifier = null): bool
    {
        $erased = false;
        foreach ($this->erasureOwners($user, $originalIdentifier) as $owner) {
            foreach ($this->store->credentialsOf($owner) as $credential) {
                $this->store->deleteWithoutFlush($credential);
                $erased = true;
            }
        }

        return $erased;
    }

    /**
     * Whether MFA keeps rows of the user in any profile (1.36, personal data), see {@see self::eraseUser()}.
     */
    public function hasStoredCredentials(UserInterface $user, ?string $originalIdentifier = null): bool
    {
        foreach ($this->erasureOwners($user, $originalIdentifier) as $owner) {
            if ($this->store->credentialsOf($owner) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Personal-data export (1.36): what MFA keeps about the user, never the secret or the codes — enrolment date, method,
     * number of unused recovery codes and the trusted devices (label, created, last used, expiry). Null without a
     * credential.
     *
     * @return array{profile: string, method: string|null, enabled: bool, enabled_at: DateTimeImmutable|null, required_since: DateTimeImmutable|null, reset_at: DateTimeImmutable|null, unused_recovery_codes: int, trusted_devices: list<array{label: string, created_at: DateTimeImmutable, last_used_at: DateTimeImmutable, expires_at: DateTimeImmutable}>}|null
     */
    public function exportUser(UserInterface $user): ?array
    {
        $credential = $this->credential($user);
        if (!$credential instanceof MfaCredential) {
            return null;
        }
        $enabled = $credential->isEnabled();

        return [
            'profile'               => $credential->getProfile(),
            'method'                => $enabled ? 'totp' : null,
            'enabled'               => $enabled,
            'enabled_at'            => $credential->getEnabledAt(),
            'required_since'        => $credential->getRequiredSince(),
            'reset_at'              => $credential->getResetAt(),
            'unused_recovery_codes' => $enabled ? $this->store->unusedRecoveryCodes($credential) : 0,
            'trusted_devices'       => array_map(static fn (array $device): array => [
                'label'        => $device['label'],
                'created_at'   => $device['created_at'],
                'last_used_at' => $device['last_used_at'],
                'expires_at'   => $device['expires_at'],
            ], $credential->getId() !== null ? $this->store->devices($credential, $this->now()) : []),
        ];
    }

    /**
     * The credential of a user in its MFA profile (null without one).
     */
    public function credential(UserInterface $user): ?MfaCredential
    {
        $profile = $this->profileFor($user);
        $owner   = $this->owners->resolve($user);

        return $profile instanceof ProfileSettings && $owner instanceof MfaOwner ? $this->store->credential($profile->name, $owner) : null;
    }

    public function totp(ProfileSettings $profile): Totp
    {
        $totp = is_array($profile->mfa['totp'] ?? null) ? $profile->mfa['totp'] : [];

        return new Totp((int) ($totp['digits'] ?? 6), (int) ($totp['period'] ?? 30), (int) ($totp['window'] ?? 1));
    }

    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function checkTotp(ProfileSettings $profile, MfaCredential $credential, string $secret, string $code): MfaCheck
    {
        $step = $this->totp($profile)->verify($secret, $code, $this->now()->getTimestamp());

        return $step !== null && $this->store->claimStep($credential, $step) ? MfaCheck::Totp : MfaCheck::Invalid;
    }

    private function checkRecovery(ProfileSettings $profile, UserInterface $user, MfaCredential $credential, string $normalized): MfaCheck
    {
        if (!$this->store->useRecoveryCode($credential, $this->recoveryCodes($profile)->hash($normalized), $this->now())) {
            return MfaCheck::Invalid;
        }
        $this->dispatch(new MfaRecoveryCodeUsedEvent($profile->name, $user, $this->store->unusedRecoveryCodes($credential)));

        return MfaCheck::Recovery;
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(ProfileSettings $profile, MfaCredential $credential): array
    {
        $generator = $this->recoveryCodes($profile);
        $codes     = $generator->generate((int) ($profile->mfa['recovery_codes']['count'] ?? 10));
        $hashes    = [];
        foreach ($codes as $code) {
            $hashes[] = $generator->hash((string) RecoveryCodes::normalize($code));
        }
        $this->store->replaceRecoveryCodes($credential, $hashes, $this->now());

        return $codes;
    }

    private function clear(ProfileSettings $profile, UserInterface $user, MfaCredential $credential, string $reason, ?string $resetBy = null): void
    {
        $credential->disable($this->now(), $resetBy);
        $this->store->save($credential);
        $this->store->replaceRecoveryCodes($credential, [], $this->now());
        $this->dropDevices($profile, $user, $credential, $reason);
    }

    private function dropDevices(ProfileSettings $profile, UserInterface $user, MfaCredential $credential, string $reason, ?string $publicId = null, ?Request $request = null): int
    {
        $deviceIds = $this->store->deleteDevices($credential, $publicId);
        $current   = $request instanceof Request ? $this->sessions?->currentHash($request) : null;
        foreach ($deviceIds as $deviceId) {
            if ($deviceId !== null && $reason === 'user') {
                $this->sessions?->revokeDevice($user, $deviceId, $current, $user);
            }
        }
        if ($deviceIds !== []) {
            $this->dispatch(new MfaTrustedDevicesForgottenEvent($profile->name, $user, $reason, count($deviceIds)));
        }

        return count($deviceIds);
    }

    private function signOutOthers(UserInterface $user, ?Request $request): void
    {
        if (!$this->sessions instanceof UserSessionManager) {
            return;
        }
        $this->sessions->revokeOthers($user, $request instanceof Request ? $this->sessions->currentHash($request) : null);
    }

    private function graceEndsAt(ProfileSettings $profile, ?MfaCredential $credential): ?DateTimeImmutable
    {
        $grace = (int) ($profile->mfa['grace_period'] ?? 604800);
        $since = $credential?->getRequiredSince() ?? $this->now();
        $ends  = $since->modify(sprintf('+%d seconds', $grace));

        return $ends > $this->now() ? $ends : null;
    }

    private function recoveryCodes(ProfileSettings $profile): RecoveryCodes
    {
        return new RecoveryCodes((string) ($profile->mfa['hash_secret'] ?? ''));
    }

    /**
     * @param array<string, mixed> $limits
     *
     * @return array{0: int, 1: int} limit, window
     */
    private function limit(array $limits, string $key, int $limit, int $window): array
    {
        $node = is_array($limits[$key] ?? null) ? $limits[$key] : [];

        return [is_int($node['limit'] ?? null) ? $node['limit'] : $limit, is_int($node['window'] ?? null) ? $node['window'] : $window];
    }

    /**
     * Owner references to erase (1.36): the current one and, when it is keyed by the user identifier and that changed
     * since the erasure started, the one under the original identifier.
     *
     * @return list<MfaOwner>
     */
    private function erasureOwners(UserInterface $user, ?string $originalIdentifier): array
    {
        $owner = $this->owners->resolve($user);
        if (!$owner instanceof MfaOwner) {
            return [];
        }
        $owners = [$owner];
        if ($originalIdentifier !== null && $originalIdentifier !== '' && $originalIdentifier !== $owner->id
            && $owner->id === $user->getUserIdentifier() && mb_strlen($originalIdentifier) <= MfaOwner::MAX_LENGTH) {
            $owners[] = new MfaOwner($owner->type, $originalIdentifier);
        }

        return $owners;
    }

    private function requireProfile(UserInterface $user): ProfileSettings
    {
        return $this->profileFor($user) ?? throw new LogicException(sprintf('No AuthKit profile with mfa.enabled for %s.', $user::class));
    }

    private function requireOwner(UserInterface $user): MfaOwner
    {
        return $this->owners->resolve($user) ?? throw new LogicException('This user cannot have MFA (no stable identifier).');
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    /**
     * Whether `$reason` is one of the UserKit revocation reasons that forget trusted devices.
     */
    public static function forgetsDevices(string $reason): bool
    {
        return in_array($reason, ['everywhere', 'admin', 'account_disabled', 'password_changed'], true);
    }
}
