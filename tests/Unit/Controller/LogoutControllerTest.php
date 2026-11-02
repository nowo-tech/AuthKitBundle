<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use LogicException;
use Nowo\AuthKitBundle\Controller\LogoutController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LogoutControllerTest extends TestCase
{
    public function testLogoutThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('route "unknown" (unknown path)');
        (new LogoutController())->logout();
    }

    public function testMessagePointsAtTheFirewallLogoutPath(): void
    {
        $request = Request::create('/logout');
        $request->attributes->set('_route', 'nowo_auth_kit_logout_unlocalized');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/route "nowo_auth_kit_logout_unlocalized" \(\/logout\).*firewall "logout\.path"/');
        (new LogoutController())->logout($request);
    }
}
