<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AvailabilityCheckersPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\InvitationsPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\LoginLinkHandlerLocatorPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\LoginThrottleRequiredPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MfaLockRequiredPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MultiAccountPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\ProgrammaticLoginAuthenticatorPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\QrCodeGeneratorPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
        $container->addCompilerPass(new QrCodeGeneratorPass());
        $container->addCompilerPass(new LoginThrottleRequiredPass());
        $container->addCompilerPass(new LoginLinkHandlerLocatorPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new MfaLockRequiredPass());
        $container->addCompilerPass(new InvitationsPass());
        $container->addCompilerPass(new MultiAccountPass());
        $container->addCompilerPass(new ProgrammaticLoginAuthenticatorPass());
        $container->addCompilerPass(new AvailabilityCheckersPass());

        $entityDir = __DIR__ . '/Entity';
        if (is_dir($entityDir)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Nowo\\AuthKitBundle\\Entity'],
                [$entityDir],
                [],
                NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER,
            ));
        }
        // 1.46.0: doctrine.mapping_type xml maps the same tables from XML (enabled by its own parameter).
        $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver(
            [__DIR__ . '/Resources/config/doctrine-xml' => 'Nowo\\AuthKitBundle\\Entity'],
            [],
            NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER,
        ));
        // 1.43.0 (audit LD-AK-M1): after the mapping passes, so the named manager's driver chain is complete.
        $container->addCompilerPass(new EntityManagerOptionPass());
        // 1.46.0: before DoctrineBundle's MiddlewaresPass (the rollback middleware of the private entity manager).
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoAuthKitExtension();
        }

        $extension = $this->extension;

        /* @phpstan-ignore identical.alwaysFalse */
        return $extension === false ? null : $extension;
    }
}
