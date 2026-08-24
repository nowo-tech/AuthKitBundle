<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Optional QR step-up: require an explicit trusted device when Device Intelligence is enabled.
 *
 * Decorates {@see QrLoginStepUpInterface}. When the profile flag is off, delegates to the inner
 * implementation (Null by default). When the flag is on, a trusted observed device is required;
 * {@see NullQrLoginStepUp} is then skipped so the default thrower does not block approve. A custom
 * inner implementation still runs after the trusted-device check. Device ID is not a credential.
 */
final class DeviceIntelligenceQrLoginStepUp implements QrLoginStepUpInterface
{
    public function __construct(
        private readonly QrLoginStepUpInterface $inner,
        private readonly RequestProfileResolver $profileResolver,
        private readonly DeviceIntelligenceContext $devices = new DeviceIntelligenceContext(),
    ) {
    }

    public function assertUnlocked(Request $request): void
    {
        $config = $this->profileResolver->resolve($request)->deviceIntelligence;
        if (!$this->devices->shouldRequireTrustedOnQrApprove($config)) {
            $this->inner->assertUnlocked($request);

            return;
        }

        if ($this->devices->fromRequest($request) === null) {
            throw new AccessDeniedException('Device observation required for QR login step-up.');
        }

        if (!$this->devices->isTrusted($request)) {
            throw new AccessDeniedException('QR login approve requires a trusted device.');
        }

        if ($this->inner instanceof NullQrLoginStepUp) {
            return;
        }

        $this->inner->assertUnlocked($request);
    }
}
