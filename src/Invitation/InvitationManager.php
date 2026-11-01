<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_diff;
use function array_replace;
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
 * outcome when `show_link_when_undelivered` is on and the e-mail could not be sent.
 *
 * Roles must be listed in `invitations.roles` (forged values are refused); checking that the inviter holds them is the
 * caller's job ({@see Security\InvitationsAccessGuard::grantableRoles()}).
 */
final readonly class InvitationManager
{
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

        $invitation = $this->invitations->findOpenByEmail($profileName, $email);
        $limits     = [$this->inviterLimit($profile, $actor)];
        if ($invitation instanceof Invitation) {
            $limits[] = $this->resendLimit($profile, $invitation);
        }
        foreach ($limits as [$key, $max, $window]) {
            if (!$this->limiter->consume($key, $max, $window)) {
                return InvitationOutcome::refused(InvitationOutcome::RATE_LIMITED, $invitation instanceof Invitation ? $this->snapshot($invitation) : null);
            }
        }

        $ttl    = $this->ttl($settings, $request->ttlDays);
        $resend = $invitation instanceof Invitation;
        if ($invitation instanceof Invitation) {
            $invitation->setRoles([...$invitation->getRoles(), ...$request->roles])
                ->setPayload(array_replace($invitation->getPayload(), $request->payload));
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

        return $this->deliver($actor, $profile, $invitation, $ttl, $resend);
    }

    /** A new link and expiry (the previous link stops working); pending and expired invitations only. */
    public function resend(?UserInterface $actor, Invitation $invitation): InvitationOutcome
    {
        if (!$invitation->isOpen()) {
            return InvitationOutcome::refused(InvitationOutcome::NOT_OPEN, $this->snapshot($invitation));
        }
        $profile = $this->profile($invitation->getProfile());
        foreach ([$this->resendLimit($profile, $invitation), $this->inviterLimit($profile, $actor)] as [$key, $max, $window]) {
            if (!$this->limiter->consume($key, $max, $window)) {
                return InvitationOutcome::refused(InvitationOutcome::RATE_LIMITED, $this->snapshot($invitation));
            }
        }

        return $this->deliver($actor, $profile, $invitation, $this->ttl($profile->invitations, $invitation->getTtlDays()), true);
    }

    /** Pending and expired invitations only; the link stops working. */
    public function revoke(?UserInterface $actor, Invitation $invitation): bool
    {
        if (!$invitation->isOpen()) {
            return false;
        }
        $now = $this->now();
        $invitation->revoke($actor?->getUserIdentifier(), $now);
        $this->invitations->save($invitation);
        $this->eventDispatcher?->dispatch(new InvitationRevokedEvent($this->snapshot($invitation), $actor, $now));

        return true;
    }

    public function snapshot(Invitation $invitation): InvitationSnapshot
    {
        return InvitationSnapshot::of($invitation, $this->now());
    }

    private function deliver(?UserInterface $actor, ProfileSettings $profile, Invitation $invitation, int $ttl, bool $resend): InvitationOutcome
    {
        [$token, $hash] = $this->tokens->generate();
        $now            = $this->now();
        $invitation->issue($hash, $ttl, $now);
        $this->invitations->save($invitation);

        $snapshot = $this->snapshot($invitation);
        /** @var array{accept: array{name: string}} $routes */
        $routes = $profile->invitations['routes'];
        $url    = $this->urlGenerator->generate($routes['accept']['name'], ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL, $invitation->getLocale());
        $sent   = $this->mailer->send(new InvitationMail($snapshot, $url, $resend));
        $show   = !$sent && ($profile->invitations['show_link_when_undelivered'] ?? false) === true;

        $delivery = $sent ? InvitationCreatedEvent::DELIVERY_EMAIL : ($show ? InvitationCreatedEvent::DELIVERY_LINK : InvitationCreatedEvent::DELIVERY_NONE);
        $this->eventDispatcher?->dispatch($resend
            ? new InvitationResentEvent($snapshot, $actor, $now, $delivery)
            : new InvitationCreatedEvent($snapshot, $actor, $now, $delivery));

        return match (true) {
            $sent   => InvitationOutcome::sent($snapshot),
            $show   => InvitationOutcome::link($snapshot, $url),
            default => InvitationOutcome::notDelivered($snapshot),
        };
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
