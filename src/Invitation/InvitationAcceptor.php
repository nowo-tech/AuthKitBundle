<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Normalizer;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationOpeningEvent;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserWriter;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_intersect;
use function array_values;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;
use function trim;

/**
 * What a link opens and the acceptance. {@see self::lookup()} answers the same `null` for an unknown, used, revoked or
 * replaced token (constant-time hash comparison); an expired one whose token is still the current one is reported as
 * such. {@see self::accept()} claims the invitation (single use: one conditional UPDATE), creates the account through
 * {@see UserRegistrar::registerInvited()} with the roles still grantable (`invitations.roles` re-checked), runs the
 * {@see InvitationGrantInterface} services and commits — all in one transaction — then dispatches
 * {@see InvitationAcceptedEvent}.
 *
 * 1.33: {@see InvitationAcceptingEvent} before the transaction (a host may refuse with a reason shown to the invitee),
 * {@see self::attempt()} (the outcome with that reason), {@see InvitationLinkRejectedEvent} for an expired or revoked
 * link ({@see self::lookup()} with `$report`), and the social acceptance of the invitation pending in the session
 * ({@see self::pending()}, {@see self::acceptPendingForSocial()}).
 *
 * 1.42: {@see self::acceptExisting()} (an existing account accepts an invitation addressed to its e-mail, with the same
 * checks and the same single-use claim), {@see self::precheck()} ({@see InvitationOpeningEvent} when a link is opened)
 * and `invitations.social.keep_pending_on_mismatch`.
 */
final readonly class InvitationAcceptor
{
    /** Session key prefix of the opened link's token hash (one per profile); read it through {@see self::pending()}. */
    public const SESSION_KEY = '_nowo_auth_kit_invitation.';

    /** {@see InvitationLinkRejectedEvent} at most once per invitation and window (seconds), SEC-004 CP L2. */
    public const REJECTED_EVENT_WINDOW = 3600;

    /**
     * @param iterable<InvitationGrantInterface> $grants
     */
    public function __construct(
        private InvitationRepository $invitations,
        private ProfileRegistry $profiles,
        private UserRegistrar $registrar,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private InvitationTokens $tokens = new InvitationTokens(),
        private iterable $grants = [],
        private ?EventDispatcherInterface $eventDispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
        private EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        private ?AuthKitAttemptLimiter $limiter = null,
        private ?UserWriterInterface $userWriter = null,
        private ?EntityManagerInterface $privateEntityManager = null,
    ) {
    }

    public function hashToken(string $token): string
    {
        return $this->tokens->hash($token);
    }

    /** The read-only copy of an invitation (no token, no hash) as of now (1.42), e.g. for a template. */
    public function snapshot(Invitation $invitation): InvitationSnapshot
    {
        return InvitationSnapshot::of($invitation, $this->now());
    }

    /**
     * The invitation behind a token hash in a profile: `[Pending, invitation]` (valid), `[Expired, invitation]` (only
     * while the token is still the current one), or `[null, null]` (no longer valid, whatever the reason).
     *
     * @param bool $report (1.33) dispatch {@see InvitationLinkRejectedEvent} when the current token of an expired or
     *                     revoked invitation is looked up
     *
     * @return array{0: InvitationStatus|null, 1: Invitation|null}
     */
    public function lookup(string $profile, string $tokenHash, bool $report = false): array
    {
        $invitation = $this->invitations->findOneByTokenHash($tokenHash);
        if (!$invitation instanceof Invitation
            || $invitation->getProfile() !== $profile
            || !$this->tokens->matchesHash($tokenHash, $invitation->getTokenHash())
        ) {
            return [null, null];
        }
        $now    = $this->now();
        $status = $invitation->status($now);
        if ($report && ($status === InvitationStatus::Expired || $status === InvitationStatus::Revoked)
            // SEC-004 CP L2: once per invitation and window, whatever the client IP.
            && (!$this->limiter instanceof AuthKitAttemptLimiter || $this->limiter->consume('invitation_rejected:' . $profile . ':' . $invitation->getPublicId(), 1, self::REJECTED_EVENT_WINDOW))) {
            $this->eventDispatcher?->dispatch(new InvitationLinkRejectedEvent(
                InvitationSnapshot::of($invitation, $now),
                $status === InvitationStatus::Expired ? InvitationLinkRejectedEvent::REASON_EXPIRED : InvitationLinkRejectedEvent::REASON_REVOKED,
                $now,
            ));
        }

        return $status->isOpen() ? [$status, $invitation] : [null, null];
    }

    /**
     * The pending invitation whose link was opened in this session for the profile (the accept page keeps its token
     * hash under {@see self::SESSION_KEY}), or null. Hosts call this instead of reading the session key.
     *
     * @phpstan-impure
     */
    public function pending(SessionInterface $session, string $profile): ?InvitationSnapshot
    {
        $tokenHash             = $session->get(self::SESSION_KEY . $profile);
        [$status, $invitation] = is_string($tokenHash) ? $this->lookup($profile, $tokenHash) : [null, null];

        return $status === InvitationStatus::Pending && $invitation instanceof Invitation ? InvitationSnapshot::of($invitation, $this->now()) : null;
    }

    /**
     * Social acceptance (1.33): accepts the invitation pending in the session (link opened on the accept page) for a
     * person who signed in with a social provider. The provider must assert a verified e-mail equal to the invited
     * one (normalised); the account is created like the form acceptance (pre-accept event, roles re-check, grants,
     * one transaction) with the invited e-mail forced into the e-mail field. The session entry is removed whatever the
     * outcome, except after an `email_mismatch` with `invitations.social.keep_pending_on_mismatch` (1.42). The caller links the social identity (e.g. {@see SocialAccountLinker}),
     * signs the user in, and must have checked the OAuth state and code itself.
     *
     * @param array<string, mixed> $formData other registration field values (e.g. a display name); never a password
     */
    public function acceptPendingForSocial(SessionInterface $session, string $profile, SocialUserProfile $social, array $formData = []): InvitationAcceptance
    {
        $key       = self::SESSION_KEY . $profile;
        $tokenHash = $session->get($key);
        $session->remove($key);
        $settings = $this->profiles->getByName($profile);
        // Same gate as the accept page: invitations enabled and a registration mode other than disabled.
        if (!$settings->invitationsEnabled() || $settings->registrationMode === RegistrationMode::Disabled->value) {
            return InvitationAcceptance::failed(InvitationAcceptance::NO_INVITATION);
        }
        [$status, $invitation] = is_string($tokenHash) ? $this->lookup($profile, $tokenHash, true) : [null, null];
        if (!$invitation instanceof Invitation || !is_string($tokenHash)) {
            return InvitationAcceptance::failed(InvitationAcceptance::NO_INVITATION);
        }
        $snapshot = InvitationSnapshot::of($invitation, $this->now());
        if ($status === InvitationStatus::Expired) {
            return InvitationAcceptance::failed(InvitationAcceptance::EXPIRED, $snapshot);
        }
        if ($social->emailVerified !== true || !is_string($social->email) || !self::sameEmail($social->email, $invitation->getEmail())) {
            // 1.42 invitations.social.keep_pending_on_mismatch: nothing was tried, so the invitee may retry (another
            // provider account, or the form) without opening the link again. The invitation itself is never touched.
            if (($settings->invitations['social']['keep_pending_on_mismatch'] ?? false) === true) {
                $session->set($key, $tokenHash);
            }

            return InvitationAcceptance::failed(InvitationAcceptance::EMAIL_MISMATCH, $snapshot);
        }
        $field = $settings->invitationEmailField();
        foreach ($settings->registrationFields as $definition) {
            if (($definition['type'] ?? 'text') === 'password' || ($definition['hash'] ?? false) === true) {
                unset($formData[(string) $definition['name']]);
            }
        }
        if ($field !== null) {
            $formData[$field] = $invitation->getEmail();
        }

        return $this->attempt($invitation, $tokenHash, $formData);
    }

    /**
     * Creates the account and applies the grants; null when the invitation is no longer pending, the account could
     * not be created (e.g. the e-mail got an account meanwhile) or a grant refused it — nothing is kept then.
     *
     * @param array<string, mixed> $formData registration form data (the e-mail field already forced when locked)
     */
    public function accept(Invitation $invitation, string $tokenHash, array $formData): ?UserInterface
    {
        return $this->attempt($invitation, $tokenHash, $formData)->user;
    }

    /**
     * {@see self::accept()} with the outcome (1.33): `accepted`, `refused` (pre-accept event or grant, with the host's
     * reason when given) or `not_accepted`. Deny by default: a listener or grant that fails refuses the acceptance.
     *
     * @param array<string, mixed> $formData registration form data (the e-mail field already forced when locked)
     */
    public function attempt(Invitation $invitation, string $tokenHash, array $formData): InvitationAcceptance
    {
        $now = $this->now();
        if ($invitation->status($now) !== InvitationStatus::Pending || !$this->tokens->matchesHash($tokenHash, $invitation->getTokenHash())) {
            return InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED);
        }
        $snapshot = InvitationSnapshot::of($invitation, $now);
        $refused  = $this->accepting($invitation, $snapshot, $now, null);
        if ($refused instanceof InvitationAcceptance) {
            return $refused;
        }
        $profile = $this->profiles->getByName($invitation->getProfile());

        return $this->claimAndGrant($invitation, $tokenHash, $snapshot, $now, $profile, null, $formData);
    }

    /**
     * An existing account accepts an invitation addressed to its e-mail (1.42), e.g. from the host's "Account →
     * Organizations" page, instead of calling {@see InvitationRepository::claim()} itself. The same checks as the accept
     * page: invitations enabled and a registration mode other than disabled, a pending invitation (`expired` reported
     * as such; accepted, revoked or without a link: `no_invitation`), the e-mail of the account equal to the invited one
     * ({@see self::sameEmail()}: ASCII case-insensitive, otherwise NFC byte-equal), {@see InvitationAcceptingEvent} with
     * {@see InvitationAcceptingEvent::$user} set (a host refuses there, e.g. no seat left), then one transaction: the
     * single-use conditional claim of the current link (a resend, revoke or concurrent acceptance in between makes it
     * fail: `not_accepted`), the {@see InvitationGrantInterface} services, the commit; then
     * {@see InvitationAcceptedEvent} with `existingAccount: true`. No account is created and no
     * {@see UserRegisteredEvent} is dispatched.
     *
     * The account must be of the invitation profile's `user_class` (`not_accepted` otherwise). AuthKit never changes
     * the roles of an existing account: an invitation that still carries a grantable role (`invitations.roles`) is
     * refused (`not_accepted`); invite existing accounts without roles, or grant them in an
     * {@see InvitationGrantInterface}. On a failure the bundle entity manager is cleared, as in {@see self::attempt()}:
     * call it outside your own transaction (its events follow its own commit) and reload your entities afterwards.
     *
     * @param UserInterface $user the signed-in account accepting it (the host checks who may act for it)
     * @param Invitation $invitation an invitation of the bundle entity manager (e.g. listed for the account's e-mail)
     * @param string $email the account's current, verified e-mail address (never a value from the request)
     */
    public function acceptExisting(UserInterface $user, Invitation $invitation, string $email): InvitationAcceptance
    {
        $now      = $this->now();
        $profile  = $this->profiles->getByName($invitation->getProfile());
        $status   = $invitation->status($now);
        $snapshot = InvitationSnapshot::of($invitation, $now);
        // Same gate as the accept page: invitations enabled and a registration mode other than disabled.
        if (!$profile->invitationsEnabled() || $profile->registrationMode === RegistrationMode::Disabled->value) {
            return InvitationAcceptance::failed(InvitationAcceptance::NO_INVITATION);
        }
        if ($status === InvitationStatus::Expired) {
            return InvitationAcceptance::failed(InvitationAcceptance::EXPIRED, $snapshot);
        }
        $tokenHash = $invitation->getTokenHash();
        if ($status !== InvitationStatus::Pending || $tokenHash === '') {
            return InvitationAcceptance::failed(InvitationAcceptance::NO_INVITATION);
        }
        if (!self::sameEmail($email, $invitation->getEmail())) {
            return InvitationAcceptance::failed(InvitationAcceptance::EMAIL_MISMATCH, $snapshot);
        }
        // KL-L4: only an account of the invitation profile's user class (a Doctrine proxy extends it).
        if (!$user instanceof $profile->userClass) {
            $this->logger->notice('AuthKit invitation of another profile\'s user class; an existing account cannot accept it.', ['invitation' => $invitation->getPublicId()]);

            return InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
        }
        if ($this->grantableRoles($profile, $invitation) !== []) {
            $this->logger->notice('AuthKit invitation carries roles; an existing account cannot accept it.', ['invitation' => $invitation->getPublicId()]);

            return InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
        }
        $refused = $this->accepting($invitation, $snapshot, $now, $user);
        if ($refused instanceof InvitationAcceptance) {
            return $refused;
        }

        return $this->claimAndGrant($invitation, $tokenHash, $snapshot, $now, $profile, $user, []);
    }

    /**
     * Pre-check of a pending invitation whose link is being opened (1.42): dispatches {@see InvitationOpeningEvent}.
     * Null: the page may show the form; otherwise `refused`, with the host's reason when given. Nothing is consumed or
     * written. Deny by default: a failing listener refuses.
     */
    public function precheck(Invitation $invitation): ?InvitationAcceptance
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return null;
        }
        $now      = $this->now();
        $snapshot = InvitationSnapshot::of($invitation, $now);
        try {
            $opening = $this->eventDispatcher->dispatch(new InvitationOpeningEvent($snapshot, $now));
        } catch (Throwable $exception) {
            $this->logger->error('AuthKit invitation opening listener failed; the link is refused for now.', ['invitation' => $invitation->getPublicId(), 'reason' => $exception::class]);

            return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot);
        }
        if (!$opening->isRefused()) {
            return null;
        }
        $this->logger->notice('AuthKit invitation refused when its link was opened.', ['invitation' => $invitation->getPublicId()]);

        return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot, $opening->getRefusal());
    }

    /**
     * {@see InvitationAcceptingEvent}: null when the acceptance may go on, otherwise the refusal (SEC-004 CP I3: a
     * failing pre-accept listener denies, it never accepts nor answers 500).
     */
    private function accepting(Invitation $invitation, InvitationSnapshot $snapshot, DateTimeImmutable $now, ?UserInterface $existing): ?InvitationAcceptance
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return null;
        }
        try {
            $accepting = $this->eventDispatcher->dispatch(new InvitationAcceptingEvent($snapshot, $now, $existing));
        } catch (Throwable $exception) {
            $this->logger->error('AuthKit invitation pre-accept listener failed; acceptance refused.', ['invitation' => $invitation->getPublicId(), 'reason' => $exception::class]);

            return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot);
        }
        if ($accepting->isRefused()) {
            $this->logger->notice('AuthKit invitation refused before acceptance.', ['invitation' => $invitation->getPublicId()]);

            return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot, $accepting->getRefusal());
        }

        return null;
    }

    /**
     * The acceptance transaction: the single-use claim, the account (created, or the existing one), the grants, the
     * commit; then the events. Nothing is kept when any step fails.
     *
     * @param array<string, mixed> $formData
     */
    private function claimAndGrant(Invitation $invitation, string $tokenHash, InvitationSnapshot $snapshot, DateTimeImmutable $now, ProfileSettings $profile, ?UserInterface $existing, array $formData): InvitationAcceptance
    {
        $roles      = $this->grantableRoles($profile, $invitation);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $user    = $existing;
        $created = null;
        try {
            $acceptedBy = $existing instanceof UserInterface ? $existing->getUserIdentifier() : $invitation->getEmail();
            if (!$this->invitations->claim($invitation, $tokenHash, $acceptedBy, $now)) {
                $connection->rollBack();

                return InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
            }
            if (!$user instanceof UserInterface) {
                // @igor-ignore - Stateless registrar call (persists a new account); no service state changes.
                $user    = $this->registrar->registerInvited($formData, $profile->name, $roles);
                $created = $user;
            }
            $invitation->markAccepted($user->getUserIdentifier(), $now);
            $snapshot = InvitationSnapshot::of($invitation, $now);
            foreach ($this->grants as $grant) {
                $grant->grant($snapshot, $user);
            }
            if ($this->privateEntityManager instanceof EntityManagerInterface) {
                // 1.46.0 private entity manager: the invitation row (same connection, same transaction).
                $this->entityManagerRecovery->flush($this->privateEntityManager);
            }
            // The application's manager (the account, the grants) inside the transaction this method opened.
            $this->entityManagerRecovery->flush($this->entityManager);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // Nothing of the failed acceptance may be flushed later in the request (the account, the claim).
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }
            // @igor-ignore - Unit of work of the bundle's private manager (1.46.0), dropped at every boundary anyway.
            $this->privateEntityManager?->clear();
            $this->compensate($profile, $created);
            if (!$exception instanceof RuntimeException) {
                throw $exception;
            }
            $this->logger->notice('AuthKit invitation not accepted.', ['invitation' => $invitation->getPublicId(), 'reason' => $exception::class]);

            return $exception instanceof InvitationRefusedException
                ? InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot, $exception->reason)
                : InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
        }

        if ($created instanceof UserInterface) {
            // 1.41: after the commit, like the registration page / social sign-up / admin creation.
            $this->eventDispatcher?->dispatch(UserRegisteredEvent::of($profile->name, $created, UserCreationSource::Invitation));
        }
        $this->eventDispatcher?->dispatch(new InvitationAcceptedEvent($snapshot, $user, $now, !$created instanceof UserInterface));

        return InvitationAcceptance::accepted($user, $snapshot);
    }

    /**
     * The roles of the invitation still grantable by configuration (`invitations.roles`, re-checked on acceptance).
     *
     * @return list<string>
     */
    private function grantableRoles(ProfileSettings $profile, Invitation $invitation): array
    {
        $grantable = is_array($profile->invitations['roles'] ?? null) ? $profile->invitations['roles'] : [];

        return array_values(array_intersect($invitation->getRoles(), $grantable));
    }

    /**
     * JW-A2: a host {@see UserWriterInterface} that stores outside this transaction is not rolled back with it; the
     * account of the failed acceptance is removed again (the invitation stays unclaimed, so it must not stay usable).
     * The Doctrine default is rolled back with the transaction. A failing removal does not hide the original outcome.
     */
    private function compensate(ProfileSettings $profile, ?UserInterface $user): void
    {
        if (!$user instanceof UserInterface || !$this->userWriter instanceof UserWriterInterface || $this->userWriter instanceof DoctrineUserWriter) {
            return;
        }

        try {
            // @igor-ignore - Stateless user port call (removes the host user); no service state changes.
            $this->userWriter->remove($profile, $user);
        } catch (Throwable $exception) {
            $this->logger->error('AuthKit could not remove the account of a failed invitation acceptance.', ['reason' => $exception::class]);
        }
    }

    /**
     * SEC-004 CP I2: the provider e-mail matches the invited one without Unicode case folding (U+212A KELVIN SIGN must
     * not become "k"): an ASCII address compares ASCII-lower-cased; any other is NFC-normalised and compared byte for
     * byte.
     */
    public static function sameEmail(string $provided, string $invited): bool
    {
        $provided = trim($provided);
        if (preg_match('/^[\x20-\x7E]+$/', $provided) === 1) {
            return strtolower($provided) === $invited;
        }
        // Non-ASCII input (checked before NFC, which maps U+212A to "K"): NFC, then byte for byte, no case folding.
        $nfc = Normalizer::normalize($provided, Normalizer::FORM_C);

        return $nfc === $invited;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
