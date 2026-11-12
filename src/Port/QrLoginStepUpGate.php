<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Serves the deprecated {@see QrLoginStepUpInterface} alias with the host's {@see QrLoginStepUpPolicyInterface}
 * (1.46.0) when the application aliases the 1.46 interface (wired by
 * {@see AuthKitPortsPass}); the bundle's Device Intelligence decorator
 * of the deprecated id still wraps it. Anything but `true` throws {@see AccessDeniedException} (403, as with the
 * deprecated interface); other exceptions propagate.
 *
 * @internal
 */
final readonly class QrLoginStepUpGate implements QrLoginStepUpInterface
{
    public function __construct(
        private QrLoginStepUpPolicyInterface $policy,
        private RequestProfileResolver $profileResolver,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function assertUnlocked(Request $request): void
    {
        $profile = $this->profileResolver->resolve($request);
        $user    = $this->tokenStorage?->getToken()?->getUser();
        $context = AuthRequestContexts::fromRequest($request, $profile->firewall, $profile->name);

        if ($this->policy->isUnlocked($context, $user instanceof UserInterface ? AuthUserRefs::fromUser($user) : null) !== true) {
            throw new AccessDeniedException('QR login step-up refused.');
        }
    }
}
