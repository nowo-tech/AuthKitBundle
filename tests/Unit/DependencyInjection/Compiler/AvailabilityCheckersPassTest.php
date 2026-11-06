<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AvailabilityCheckersPass;
use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\ToggleAvailabilityChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class AvailabilityCheckersPassTest extends TestCase
{
    public function testNothingConfiguredIsANoOp(): void
    {
        $container = new ContainerBuilder();
        (new AvailabilityCheckersPass())->process($container);

        self::assertFalse($container->hasParameter(AvailabilityCheckersPass::PARAMETER));
    }

    public function testAcceptsACheckerAndAServiceWithoutClass(): void
    {
        $container = $this->container(['ok', 'factory']);
        $container->register('ok', ToggleAvailabilityChecker::class);
        $container->setDefinition('factory', (new Definition())->setFactory([stdClass::class, 'create']));

        (new AvailabilityCheckersPass())->process($container);

        self::assertFalse($container->hasParameter(AvailabilityCheckersPass::PARAMETER));
    }

    public function testRefusesAServiceOfAnotherType(): void
    {
        $container = $this->container(['wrong']);
        $container->register('wrong', AlwaysOutboundMailReadyChecker::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('availability checker "wrong" must implement');

        (new AvailabilityCheckersPass())->process($container);
    }

    public function testFollowsTheParentOfAChildDefinition(): void
    {
        $container = $this->container(['child']);
        $container->register('parent', AlwaysOutboundMailReadyChecker::class);
        $container->setDefinition('child', new ChildDefinition('parent'));

        $this->expectException(InvalidArgumentException::class);

        (new AvailabilityCheckersPass())->process($container);
    }

    public function testRefusesAMissingService(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        (new AvailabilityCheckersPass())->process($this->container(['missing']));
    }

    /**
     * @param list<string> $ids
     */
    private function container(array $ids): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(AvailabilityCheckersPass::PARAMETER, $ids);

        return $container;
    }
}
