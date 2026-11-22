<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use PHPUnit\Framework\TestCase;

final class NullQrLoginStepUpTest extends TestCase
{
    public function testAlwaysRefuses(): void
    {
        $stepUp = new NullQrLoginStepUp();

        self::assertFalse($stepUp->isUnlocked(AuthRequestContext::fromValues('POST', '/approve'), null));
    }
}
