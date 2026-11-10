<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\User\UserIds;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after an invitation was accepted and committed (before the sign-in). `actor` and `user` are the account
 * that accepted it: the new account, or (1.42, `existingAccount`) the existing one of
 * {@see InvitationAcceptor::acceptExisting()}.
 *
 * 1.42: the scalar ids, like the 1.41 user events, so a listener can raise its own domain event or enqueue a message
 * without holding the bundle's objects: {@see self::$userClass}, {@see self::$userId}, {@see self::$userIdentifier},
 * {@see self::invitationId()}, {@see self::profile()}.
 */
final class InvitationAcceptedEvent extends InvitationEvent
{
    /** @var class-string */
    public readonly string $userClass;

    public readonly ?string $userId;

    public readonly string $userIdentifier;

    /**
     * @param bool $existingAccount (1.42) true: an existing account accepted it ({@see InvitationAcceptor::acceptExisting()}); no account was created
     */
    public function __construct(
        InvitationSnapshot $invitation,
        public readonly UserInterface $user,
        DateTimeImmutable $occurredAt,
        public readonly bool $existingAccount = false,
    ) {
        parent::__construct($invitation, $user, $occurredAt);
        $this->userClass      = UserIds::className($user);
        $this->userId         = UserIds::of($user);
        $this->userIdentifier = $user->getUserIdentifier();
    }

    public function invitationId(): string
    {
        return $this->invitation->publicId;
    }

    public function profile(): string
    {
        return $this->invitation->profile;
    }
}
