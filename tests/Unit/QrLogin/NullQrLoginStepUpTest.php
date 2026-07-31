<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class NullQrLoginStepUpTest extends TestCase
{
    public function testThrowsAccessDeniedException(): void
    {
        $stepUp = new NullQrLoginStepUp();

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('QR login step-up not implemented');

        $stepUp->assertUnlocked(Request::create('/approve'));
    }
}
