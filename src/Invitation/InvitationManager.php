<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_diff;
use function count;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

/**
 * Invite (or update the open invitation of the same e-mail and send it again), resend (a new token voids the previous
 * link) and revoke. Rate limited per inviter (`invite_rate_limit`) and per invitation (`resend_rate_limit`). Every
 * mutation dispatches its audit event ({@see Event\InvitationEvent}); the token leaves only in the e-mail, or in the
 * outcome when `show_link_when_undelivered` is on and the e-mail could not be sent, or when the host asks for it to send
 * its own mail (`returnLink`, 1.33). With `invitations.merge_key` (1.33) the open invitation a re-invite updates is the
 * one of the same e-mail and `payload[merge_key]`; `invitations.payload_merge` says how the payloads merge.
 *
 * Roles must be listed in `invitations.roles` (forged values throw). The actor must hold every role it hands out, those
 * of an open invitation it resends, re-invites or revokes included (role hierarchy, {@see InvitationsAccessGuard::holdsAll()};
 * `NOT_ALLOWED` otherwise), and the "shown once" link is never returned to an actor lacking one (SEC-004 CD M1). Nothing
 * is sent while the registration is closed (`CLOSED`) or `absolute_url.base_uri` is empty at runtime
 * (`NOT_CONFIGURED`, SEC-004 CD L1).
 */
final readonly class InvitationManager
{
    /** Invitations revoked per batch by {@see self::revokeOpenBy()}. */
    public const REVOKE_BATCH = 100;

    public const REVOKE_MAX_BATCHES = 100;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private InvitationRepository $invitations,
        private ProfileRegistry $profiles,
        private InvitationMailerInterface $mailer,
        private AuthKitAttemptLimiter $limiter,
        private AuthKitUrlGenerator $urlGenerator,
        private ClockInterface $clock,
        private InvitationTokens $tokens = new InvitationTokens(),
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $enabledLocales = [],
        private ?InvitationsAccessGuard $guard = null,
        private ?AbsoluteUrlGenerator $absoluteUrls = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function invite(?UserInterface $actor, string $profileName, InvitationRequest $request): InvitationOutcome
    {
        $profile  = $this->profile($profileName);
        $settings = $profile->invitations;
        $email    = Invitation::normalizeEmail($request->email);
        if (mb_strlen($email) > Invitation::EMAIL_MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid invitation e-mail.');
        }
        $grantable = is_array($settings['roles'] ?? null) ? $settings['roles'] : [];
        $forged    = array_diff($request->roles, $grantable);
        if ($forged !== []) {
            throw new InvalidArgumentException(sprintf('Roles not grantable by invitation in profile "%s": %s.', $profileName, implode(', ', $forged)));
        }
        $refusal = $this->refusal($profile);
        if ($refusal !== null) {
            return InvitationOutcome::refused($refusal);
        }

        $mergeKey   = is_string($settings['merge_key'] ?? null) ? $settings['merge_key'] : null;
        $invitation = $mergeKey === null
            ? $this->invitations->findOpenByEmail($profileName, $email)
            : $this->invitations->findOpenByEmailAndPayloadKey($profileName, $email, $mergeKey, $request->payload[$mergeKey] ?? null);
        // SEC-004 CD M1: the actor must hold every role they hand out, those of an open invitation they would resend
        // through the merge included (no union beyond what the actor can grant).
        if (!$this->holdsAll($actor, $request->roles) || ($invitation instanceof Invitation && !$this->holdsAll($actor, $invitation->getRoles()))) {
            return InvitationOutcome::refused(InvitationOutcome::NOT_ALLOWED, $invitation instanceof Invitation ? $this->snapshot($invitation) : null);
        }
        $limits = [$this->inviterLimit($profile, $actor), $this->addressLimit($profile, $actor, $email)];
        if ($invitation instanceof Invitation) {
            $limits[] = $this->resendLimit($profile, $invitation);
        }
        foreach ($limits as [$key, $max, $window]) {
            if (!$this->limiter->consume($key, $max, $window)) {
                return InvitationOutcome::refused(InvitationOutcome::RATE_LIMITED, $invitation instanceof Invitation ? $this->snapshot($invitation) : null, $this->retryAfter($window));
            }
        }

        $ttl    = $this->ttl($settings, $request->ttlDays);
        $resend = $invitation instanceof Invitation;
        if ($invitation instanceof Invitation) {
            $invitation->setRoles([...$invitation->getRoles(), ...$request->roles])
                ->setPayload(InvitationPayloadMerger::merge((string) ($settings['payload_merge'] ?? InvitationsConfiguration::PAYLOAD_MERGE_REPLACE), $invitation->getPayload(), $request->payload));
        } else {
            $invitation = (new Invitation($profileName, $email, $actor?->getUserIdentifier(), $ttl, $this->now()))
                ->setRoles($request->roles)
                ->setPayload($request->payload);
        }
        if ($request->message !== null && $request->message !== '') {
            // @igor-ignore - Doctrine entity of this call (loaded or created here), not a shared service.
            $invitation->setMessage($request->message);
        }
        // @igor-ignore - Doctrine entity of this call (loaded or created here), not a shared service.
        $invitation->setLocale($this->locale($request->locale));

        return $this->deliver($actor, $profile, $invitation, $ttl, $resend, $request->returnLink);
    }

    /**
     * A new link and expiry (the previous link stops working); pending and expired invitations only, and only by an
     * actor holding every role of the invitation (SEC-004 CD M1).
     *
     * @param int|null $ttlDays validity of the new link (1.33): one of the offered validities
     *                          ({@see InvitationsConfiguration::ttlChoices()}), else `ttl_days`; null keeps the invitation's
     * @param bool $returnLink (1.33) send no e-mail and return the link in the outcome ({@see InvitationOutcome::LINK}),
     *                         for a host that sends its own mail; never logged
     */
    public function resend(?UserInterface $actor, Invitation $invitation, ?int $ttlDays = null, bool $returnLink = false): InvitationOutcome
    {
        if (!$invitation->isOpen()) {
            return InvitationOutcome::refused(InvitationOutcome::NOT_OPEN, $this->snapshot($invitation));
        }
        $profile = $this->profile($invitation->getProfile());
        $refusal = $this->refusal($profile) ?? ($this->holdsAll($actor, $invitation->getRoles()) ? null : InvitationOutcome::NOT_ALLOWED);
        if ($refusal !== null) {
            return InvitationOutcome::refused($refusal, $this->snapshot($invitation));
        }
        foreach ([$this->resendLimit($profile, $invitation), $this->inviterLimit($profile, $actor), $this->addressLimit($profile, $actor, $invitation->getEmail())] as [$key, $max, $window]) {
            if (!$this->limiter->consume($key, $max, $window)) {
                return InvitationOutcome::refused(InvitationOutcome::RATE_LIMITED, $this->snapshot($invitation), $this->retryAfter($window));
            }
        }

        return $this->deliver($actor, $profile, $invitation, $this->ttl($profile->invitations, $ttlDays ?? $invitation->getTtlDays()), true, $returnLink);
    }

    /**
     * Pending and expired invitations only, by an actor holding every role of the invitation (SEC-004 CD M1); the
     * link stops working. Outcome {@see InvitationOutcome::REVOKED}, `NOT_OPEN` or `NOT_ALLOWED`.
     */
    public function revoke(?UserInterface $actor, Invitation $invitation): InvitationOutcome
    {
        if (!$invitation->isOpen()) {
            return InvitationOutcome::refused(InvitationOutcome::NOT_OPEN, $this->snapshot($invitation));
        }
        if (!$this->holdsAll($actor, $invitation->getRoles())) {
            return InvitationOutcome::refused(InvitationOutcome::NOT_ALLOWED, $this->snapshot($invitation));
        }

        return InvitationOutcome::revoked($this->close($actor, $invitation));
    }

    /**
     * Host helper (SEC-004 CD L3): revokes the open invitations issued by `$inviter` (security identifier), e.g. from
     * the host's "user demoted / disabled / deleted" listener, so an inviter's pending invitations do not outlive the
     * roles they no longer hold. No role check (the host decides); bounded batches; one revoked event per invitation.
     *
     * @return int revoked invitations
     */
    public function revokeOpenBy(string $inviter, ?UserInterface $actor = null, ?string $profile = null): int
    {
        $revoked = 0;
        for ($batch = 0; $batch < self::REVOKE_MAX_BATCHES; ++$batch) {
            $open = $this->invitations->findOpenByInviter($inviter, self::REVOKE_BATCH, $profile);
            foreach ($open as $invitation) {
                $this->close($actor, $invitation);
                ++$revoked;
            }
            if (count($open) < self::REVOKE_BATCH) {
                break;
            }
        }

        return $revoked;
    }

    public function snapshot(Invitation $invitation): InvitationSnapshot
    {
        return InvitationSnapshot::of($invitation, $this->now());
    }

    private function deliver(?UserInterface $actor, ProfileSettings $profile, Invitation $invitation, int $ttl, bool $resend, bool $returnLink): InvitationOutcome
    {
        [$token, $hash] = $this->tokens->generate();
        $now            = $this->now();
        $invitation->issue($hash, $ttl, $now);
        $this->invitations->save($invitation);

        $snapshot = $this->snapshot($invitation);
        /** @var array{accept: array{name: string}} $routes */
        $routes = $profile->invitations['routes'];
        $url    = $this->urlGenerator->generate($routes['accept']['name'], ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL, $invitation->getLocale());
        // SEC-004 CD M1: the link is never shown nor returned to an actor lacking a role of the invitation.
        $holds = $this->holdsAll($actor, $invitation->getRoles());
        // returnLink (1.33): the host sends its own mail with the returned link.
        $sent = !$returnLink && $this->mailer->send(new InvitationMail($snapshot, $url, $resend));
        $show = !$sent && $holds && ($returnLink || ($profile->invitations['show_link_when_undelivered'] ?? false) === true);

        $delivery = match (true) {
            $sent                => InvitationCreatedEvent::DELIVERY_EMAIL,
            $show && $returnLink => InvitationCreatedEvent::DELIVERY_HOST,
            $show                => InvitationCreatedEvent::DELIVERY_LINK,
            default              => InvitationCreatedEvent::DELIVERY_NONE,
        };
        $this->eventDispatcher?->dispatch($resend
            ? new InvitationResentEvent($snapshot, $actor, $now, $delivery)
            : new InvitationCreatedEvent($snapshot, $actor, $now, $delivery));

        return match (true) {
            $sent   => InvitationOutcome::sent($snapshot),
            $show   => InvitationOutcome::link($snapshot, $url),
            default => InvitationOutcome::notDelivered($snapshot),
        };
    }

    private function close(?UserInterface $actor, Invitation $invitation): InvitationSnapshot
    {
        $now = $this->now();
        $invitation->revoke($actor?->getUserIdentifier(), $now);
        $this->invitations->save($invitation);
        $snapshot = $this->snapshot($invitation);
        $this->eventDispatcher?->dispatch(new InvitationRevokedEvent($snapshot, $actor, $now));

        return $snapshot;
    }

    /**
     * Why nothing may be sent for the profile: the registration is closed (`registration_mode: disabled` — a link could
     * not be accepted), or `absolute_url.base_uri` resolved empty at runtime (SEC-004 CD L1: the link would follow the
     * request Host). Null: sending is allowed.
     */
    private function refusal(ProfileSettings $profile): ?string
    {
        if ($profile->registrationMode === RegistrationMode::Disabled->value) {
            return InvitationOutcome::CLOSED;
        }
        if (!$this->absoluteUrls instanceof AbsoluteUrlGenerator || !$this->absoluteUrls->isEnabled()) {
            $this->logger->error('AuthKit invitations: absolute_url.base_uri is empty at runtime; no invitation link is built.');

            return InvitationOutcome::NOT_CONFIGURED;
        }

        return null;
    }

    /**
     * @param list<string> $roles
     */
    private function holdsAll(?UserInterface $actor, array $roles): bool
    {
        return $roles === [] || ($this->guard instanceof InvitationsAccessGuard && $this->guard->holdsAll($actor, $roles));
    }

    /**
     * Latest moment a refused send may be retried: the counters use fixed windows, so at most one window from now.
     */
    private function retryAfter(int $window): DateTimeImmutable
    {
        return $this->now()->modify(sprintf('+%d seconds', $window));
    }

    /**
     * Sends by one actor to one address (invite, re-invite, resend, and invite after revoke alike), SEC-004 CD I5.
     * Keyed per actor as well (CD N2): one administrator cannot block an address for the others.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function addressLimit(ProfileSettings $profile, ?UserInterface $actor, string $email): array
    {
        return [
            'invitation_email:' . $profile->name . ':' . ($actor?->getUserIdentifier() ?? '~anonymous') . ':' . Invitation::normalizeEmail($email),
            (int) ($profile->invitations['resend_rate_limit'] ?? 3) + 1,
            (int) ($profile->invitations['resend_rate_window'] ?? 3600),
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function inviterLimit(ProfileSettings $profile, ?UserInterface $actor): array
    {
        return [
            'invitation_invite:' . $profile->name . ':' . ($actor?->getUserIdentifier() ?? '~anonymous'),
            (int) ($profile->invitations['invite_rate_limit'] ?? 20),
            (int) ($profile->invitations['invite_rate_window'] ?? 3600),
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function resendLimit(ProfileSettings $profile, Invitation $invitation): array
    {
        return [
            'invitation_resend:' . $profile->name . ':' . $invitation->getPublicId(),
            (int) ($profile->invitations['resend_rate_limit'] ?? 3),
            (int) ($profile->invitations['resend_rate_window'] ?? 3600),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function ttl(array $settings, ?int $days): int
    {
        $choices = InvitationsConfiguration::ttlChoices($settings);

        return $days !== null && in_array($days, $choices, true) ? $days : (int) ($settings['ttl_days'] ?? 7);
    }

    private function locale(?string $locale): ?string
    {
        return is_string($locale) && in_array($locale, $this->enabledLocales, true) ? $locale : null;
    }

    private function profile(string $name): ProfileSettings
    {
        $profile = $this->profiles->getByName($name);
        if (!$profile->invitationsEnabled()) {
            throw new LogicException(sprintf('Invitations are disabled in the AuthKit profile "%s" (invitations.mode).', $name));
        }

        return $profile;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
