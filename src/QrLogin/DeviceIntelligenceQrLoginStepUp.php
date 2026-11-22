<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Optional QR step-up: require an explicit trusted device when Device Intelligence is enabled.
 *
 * Decorates {@see QrLoginStepUpPolicyInterface} (2.0: the port; it decorated the removed QrLoginStepUpInterface). When
 * the profile flag is off, delegates to the inner policy ({@see NullQrLoginStepUp} by default). When the flag is on, a
 * trusted observed device is required; {@see NullQrLoginStepUp} is then skipped so the default refusal does not block
 * the approval. A custom inner policy still runs after the trusted-device check. Device ID is not a credential.
 *
 * The device check reads the current request (the observed device cookie): without one it refuses (fail closed), and
 * so does an untrusted or unobserved device. Stateless (worker safe).
 */
final class DeviceIntelligenceQrLoginStepUp implements QrLoginStepUpPolicyInterface
{
    public function __construct(
        private readonly QrLoginStepUpPolicyInterface $inner,
        private readonly RequestProfileResolver $profileResolver,
        private readonly DeviceIntelligenceContext $devices = new DeviceIntelligenceContext(),
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool
    {
        $current = $this->requestStack?->getCurrentRequest();
        if (!$current instanceof Request) {
            return false;
        }
        $config = $this->profileResolver->resolve($current)->deviceIntelligence;
        if (!$this->devices->shouldRequireTrustedOnQrApprove($config)) {
            return $this->inner->isUnlocked($request, $user);
        }

        if ($this->devices->fromRequest($current) === null || !$this->devices->isTrusted($current)) {
            return false;
        }

        if ($this->inner instanceof NullQrLoginStepUp) {
            return true;
        }

        return $this->inner->isUnlocked($request, $user);
    }
}
