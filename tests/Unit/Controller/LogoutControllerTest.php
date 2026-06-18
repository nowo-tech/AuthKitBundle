<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use LogicException;
use Nowo\AuthKitBundle\Controller\LogoutController;
use PHPUnit\Framework\TestCase;

final class LogoutControllerTest extends TestCase
{
    public function testLogoutThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        (new LogoutController())->logout();
    }
}
