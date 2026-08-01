<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Endroid\QrCode\Writer\PngWriter;
use Nowo\AuthKitBundle\QrLogin\EndroidQrCodeGenerator;
use Nowo\AuthKitBundle\QrLogin\QrCodeGeneratorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When endroid/qr-code is installed, prefer EndroidQrCodeGenerator over Null.
 */
final class QrCodeGeneratorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(PngWriter::class)) {
            return; // @codeCoverageIgnore
        }

        if (!$container->hasDefinition(EndroidQrCodeGenerator::class)
            && !$container->hasAlias(EndroidQrCodeGenerator::class)
        ) {
            return;
        }

        $container->setAlias(QrCodeGeneratorInterface::class, EndroidQrCodeGenerator::class)
            ->setPublic(false);
    }
}
