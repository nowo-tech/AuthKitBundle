<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_intersect;
use function array_values;
use function is_array;

/**
 * What a link opens and the acceptance. {@see self::lookup()} answers the same `null` for an unknown, used, revoked or
 * replaced token (constant-time hash comparison); an expired one whose token is still the current one is reported as
 * such. {@see self::accept()} claims the invitation (single use: one conditional UPDATE), creates the account through
 * {@see UserRegistrar::registerInvited()} with the roles still grantable (`invitations.roles` re-checked), runs the
 * {@see InvitationGrantInterface} services and commits — all in one transaction — then dispatches
 * {@see InvitationAcceptedEvent}.
 */
final readonly class InvitationAcceptor
{
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
     * @return array{0: InvitationStatus|null, 1: Invitation|null}
     */
    public function lookup(string $profile, string $tokenHash): array
    {
        $invitation = $this->invitations->findOneByTokenHash($tokenHash);
        if (!$invitation instanceof Invitation
            || $invitation->getProfile() !== $profile
            || !$this->tokens->matchesHash($tokenHash, $invitation->getTokenHash())
        ) {
            return [null, null];
        }
        $status = $invitation->status($this->now());

        return $status->isOpen() ? [$status, $invitation] : [null, null];
    }

    /**
     * Creates the account and applies the grants; null when the invitation is no longer pending, the account could
     * not be created (e.g. the e-mail got an account meanwhile) or a grant refused it — nothing is kept then.
     *
     * @param array<string, mixed> $formData registration form data (the e-mail field already forced when locked)
     */
    public function accept(Invitation $invitation, string $tokenHash, array $formData): ?UserInterface
    {
        $now = $this->now();
        if ($invitation->status($now) !== InvitationStatus::Pending || !$this->tokens->matchesHash($tokenHash, $invitation->getTokenHash())) {
            return null;
        }
        $profile   = $this->profiles->getByName($invitation->getProfile());
        $grantable = is_array($profile->invitations['roles'] ?? null) ? $profile->invitations['roles'] : [];
        $roles     = array_values(array_intersect($invitation->getRoles(), $grantable));

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->invitations->claim($invitation, $tokenHash, $invitation->getEmail(), $now)) {
                $connection->rollBack();

                return null;
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

            return null;
        }

        $this->eventDispatcher?->dispatch(new InvitationAcceptedEvent($snapshot, $user, $now));

        return $user;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
