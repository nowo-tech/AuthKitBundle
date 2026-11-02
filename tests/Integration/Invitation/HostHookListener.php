<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Closure;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The 1.33 host hooks: records rejected links, refuses before acceptance (`refuseBefore`) or in the grant
 * (`refuseInGrant`) with a translatable reason.
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

    public static function getSubscribedEvents(): array
    {
        return [
            InvitationLinkRejectedEvent::class => 'rejected',
            InvitationAcceptingEvent::class    => 'accepting',
        ];
    }

    public function rejected(InvitationLinkRejectedEvent $event): void
    {
        $this->rejected[] = $event;
    }

    public function accepting(InvitationAcceptingEvent $event): void
    {
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
