<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Closure;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationOpeningEvent;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The 1.33 host hooks: records rejected links, refuses before acceptance (`refuseBefore`) or in the grant
 * (`refuseInGrant`) with a translatable reason. 1.42: refuses (`refuseOpening`) or fails (`crashOpening`,
 * `crashBefore`) when a link is opened, and records who accepts (`acceptingUsers`) and the registrations.
 */
final class HostHookListener implements EventSubscriberInterface, InvitationGrantInterface, InvitationActionAuthorizerInterface
{
    /** @var (Closure(?UserInterface, string, Invitation|InvitationRequest, ?InvitationRequest): bool)|null */
    public ?Closure $authorize = null;

    /** @var list<string> actions asked to the authorizer */
    public array $asked = [];

    /** @var list<InvitationLinkRejectedEvent> */
    public array $rejected = [];

    public ?InvitationRefusal $refuseBefore = null;

    public ?InvitationRefusal $refuseInGrant = null;

    public ?InvitationRefusal $refuseOpening = null;

    public bool $refuseOpeningWithoutReason = false;

    public bool $crashOpening = false;

    public bool $crashBefore = false;

    /** @var int how many times the opening hook ran */
    public int $openings = 0;

    /** @var list<string|null> identifier of the existing account of each pre-accept event (null: a new account) */
    public array $acceptingUsers = [];

    /** @var list<UserRegisteredEvent> */
    public array $registered = [];

    public static function getSubscribedEvents(): array
    {
        return [
            InvitationLinkRejectedEvent::class => 'rejected',
            InvitationAcceptingEvent::class    => 'accepting',
            InvitationOpeningEvent::class      => 'opening',
            UserRegisteredEvent::class         => 'registeredUser',
        ];
    }

    public function rejected(InvitationLinkRejectedEvent $event): void
    {
        $this->rejected[] = $event;
    }

    public function opening(InvitationOpeningEvent $event): void
    {
        ++$this->openings;
        if ($this->crashOpening) {
            throw new RuntimeException('Opening hook bug.');
        }
        if ($this->refuseOpening instanceof InvitationRefusal) {
            $event->refuse($this->refuseOpening);
            $event->refuse(new InvitationRefusal('host.second_reason_ignored'));
        } elseif ($this->refuseOpeningWithoutReason) {
            $event->refuse();
        }
    }

    public function registeredUser(UserRegisteredEvent $event): void
    {
        $this->registered[] = $event;
    }

    public function accepting(InvitationAcceptingEvent $event): void
    {
        $this->acceptingUsers[] = $event->isExistingAccount() ? $event->user?->getUserIdentifier() : null;
        if ($this->crashBefore) {
            throw new RuntimeException('Pre-accept hook bug.');
        }
        if ($this->refuseBefore instanceof InvitationRefusal) {
            $event->refuse($this->refuseBefore);
        }
    }

    public function isGranted(?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        $this->asked[] = $action;

        return !$this->authorize instanceof Closure || ($this->authorize)($actor, $action, $subject, $request);
    }

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        if ($this->refuseInGrant instanceof InvitationRefusal) {
            throw InvitationRefusedException::because($this->refuseInGrant);
        }
    }
}
