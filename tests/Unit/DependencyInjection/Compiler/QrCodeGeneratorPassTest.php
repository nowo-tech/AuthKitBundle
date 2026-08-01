<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Endroid\QrCode\Writer\PngWriter;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\QrCodeGeneratorPass;
use Nowo\AuthKitBundle\QrLogin\EndroidQrCodeGenerator;
use Nowo\AuthKitBundle\QrLogin\NullQrCodeGenerator;
use Nowo\AuthKitBundle\QrLogin\QrCodeGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class QrCodeGeneratorPassTest extends TestCase
{
    public function testAliasesInterfaceToEndroidWhenAvailable(): void
    {
        if (!class_exists(PngWriter::class)) {
            self::markTestSkipped('endroid/qr-code not installed');
        }

        $container = new ContainerBuilder();
        $container->setDefinition(EndroidQrCodeGenerator::class, new Definition(EndroidQrCodeGenerator::class));
        $container->setAlias(QrCodeGeneratorInterface::class, NullQrCodeGenerator::class);

        (new QrCodeGeneratorPass())->process($container);

        self::assertTrue($container->hasAlias(QrCodeGeneratorInterface::class));
        self::assertSame(
            EndroidQrCodeGenerator::class,
            (string) $container->getAlias(QrCodeGeneratorInterface::class),
        );
    }

    public function testNoOpWhenEndroidServiceMissing(): void
    {
        if (!class_exists(PngWriter::class)) {
            self::markTestSkipped('endroid/qr-code not installed');
        }

        $container = new ContainerBuilder();
        $container->setAlias(QrCodeGeneratorInterface::class, NullQrCodeGenerator::class);

        (new QrCodeGeneratorPass())->process($container);

        self::assertSame(
            NullQrCodeGenerator::class,
            (string) $container->getAlias(QrCodeGeneratorInterface::class),
        );
    }
}
