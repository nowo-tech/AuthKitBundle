<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Controller\LogoutController;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\ControllerRouteAttributesPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ControllerRouteAttributesPassTest extends TestCase
{
    public function testRemovesTheRoutingControllerTagFromAuthKitControllersOnly(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LoginController::class, (new Definition(LoginController::class))->addTag('routing.controller')->addTag('controller.service_arguments'));
        $container->setDefinition(LogoutController::class, (new Definition())->addTag('routing.controller'));
        $container->setDefinition('app.home', (new Definition('App\\Controller\\HomeController'))->addTag('routing.controller'));

        (new ControllerRouteAttributesPass())->process($container);

        self::assertFalse($container->getDefinition(LoginController::class)->hasTag('routing.controller'));
        self::assertTrue($container->getDefinition(LoginController::class)->hasTag('controller.service_arguments'));
        self::assertFalse($container->getDefinition(LogoutController::class)->hasTag('routing.controller'), 'A definition without class falls back to its id.');
        self::assertTrue($container->getDefinition('app.home')->hasTag('routing.controller'));
    }
}
