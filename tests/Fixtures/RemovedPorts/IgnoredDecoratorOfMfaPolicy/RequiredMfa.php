<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\IgnoredDecoratorOfMfaPolicy;

use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): an MFA requirement still decorating the removed MfaPolicyInterface id
 * with `onInvalid: IGNORE` (Symfony would remove it silently: MFA would stop being required).
 */
#[AsDecorator('Nowo\AuthKitBundle\Mfa\MfaPolicyInterface', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class RequiredMfa implements MfaRequirementPolicyInterface
{
    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement
    {
        return MfaRequirement::Required;
    }
}
