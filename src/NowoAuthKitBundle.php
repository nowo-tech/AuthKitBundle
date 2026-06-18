<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for configurable login and registration flows.
 */
class NowoAuthKitBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoAuthKitBundle';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoAuthKitExtension();
        }

        $extension = $this->extension;

        /* @phpstan-ignore identical.alwaysFalse */
        return $extension === false ? null : $extension;
    }
}
