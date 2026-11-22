<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\DecoratorOfQrStepUp;

use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host restriction of the QR approval moved to the port but still
 * decorating the removed QrLoginStepUpInterface id. Silently dropped, the restriction would be lost.
 */
#[AsDecorator('Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface')]
final class RestrictedStepUp implements QrLoginStepUpPolicyInterface
{
    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool
    {
        return false;
    }
}
