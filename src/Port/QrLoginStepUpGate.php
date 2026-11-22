<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Port\Internal\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\DeviceIntelligenceQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * AuthKit's internal {@see QrLoginStepUpInterface} over the {@see QrLoginStepUpPolicyInterface} port (1.46.0; 2.0:
 * always, see services.yaml), decorators of the port ({@see DeviceIntelligenceQrLoginStepUp}) included. Anything but
 * `true` throws {@see AccessDeniedException} (403); other exceptions propagate.
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

        if (!$this->policy->isUnlocked($context, $user instanceof UserInterface ? AuthUserRefs::fromUser($user) : null)) {
            throw new AccessDeniedException('QR login step-up refused.');
        }
    }
}
