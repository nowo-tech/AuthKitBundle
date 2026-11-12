<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/** Unlocked when the session holds `step_up_ok` (build-pass and kernel fixtures). */
final class StepUpHost implements QrLoginStepUpPolicyInterface
{
    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool
    {
        return $user !== null && $request->sessionRemove('step_up_ok') === $user->identifier;
    }
}
