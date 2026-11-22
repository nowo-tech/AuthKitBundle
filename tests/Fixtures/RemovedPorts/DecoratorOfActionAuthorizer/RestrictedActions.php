<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\DecoratorOfActionAuthorizer;

use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host rule on invitation actions still decorating the removed
 * InvitationActionAuthorizerInterface id.
 */
#[AsDecorator('Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface')]
final class RestrictedActions implements InvitationActionPolicyInterface
{
    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        return false;
    }
}
