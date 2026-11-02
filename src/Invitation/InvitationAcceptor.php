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
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
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
    ) {
    }

    public function hashToken(string $token): string
    {
        return $this->tokens->hash($token);
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
     * outcome. The caller links the social identity (e.g. {@see SocialAccountLinker}),
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
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            try {
                $accepting = $this->eventDispatcher->dispatch(new InvitationAcceptingEvent($snapshot, $now));
            } catch (Throwable $exception) {
                // SEC-004 CP I3: a failing pre-accept listener denies (refused), it never accepts nor answers 500.
                $this->logger->error('AuthKit invitation pre-accept listener failed; acceptance refused.', ['invitation' => $invitation->getPublicId(), 'reason' => $exception::class]);

                return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot);
            }
            if ($accepting->isRefused()) {
                $this->logger->notice('AuthKit invitation refused before acceptance.', ['invitation' => $invitation->getPublicId()]);

                return InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot, $accepting->getRefusal());
            }
        }
        $profile   = $this->profiles->getByName($invitation->getProfile());
        $grantable = is_array($profile->invitations['roles'] ?? null) ? $profile->invitations['roles'] : [];
        $roles     = array_values(array_intersect($invitation->getRoles(), $grantable));

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->invitations->claim($invitation, $tokenHash, $invitation->getEmail(), $now)) {
                $connection->rollBack();

                return InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
            }
            // @igor-ignore - Stateless registrar call (persists a new account); no service state changes.
            $user = $this->registrar->registerInvited($formData, $profile->name, $roles);
            $invitation->markAccepted($user->getUserIdentifier(), $now);
            $snapshot = InvitationSnapshot::of($invitation, $now);
            foreach ($this->grants as $grant) {
                $grant->grant($snapshot, $user);
            }
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
            if (!$exception instanceof RuntimeException) {
                throw $exception;
            }
            $this->logger->notice('AuthKit invitation not accepted.', ['invitation' => $invitation->getPublicId(), 'reason' => $exception::class]);

            return $exception instanceof InvitationRefusedException
                ? InvitationAcceptance::failed(InvitationAcceptance::REFUSED, $snapshot, $exception->reason)
                : InvitationAcceptance::failed(InvitationAcceptance::NOT_ACCEPTED, $snapshot);
        }

        $this->eventDispatcher?->dispatch(new InvitationAcceptedEvent($snapshot, $user, $now));

        return InvitationAcceptance::accepted($user, $snapshot);
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
