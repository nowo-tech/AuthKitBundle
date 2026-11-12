<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Container;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager\PrivateEmMfaTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\XmlMapping\XmlMfaTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `lint:container` (argument types of every service definition) on the default configuration and with the 1.46.0
 * Doctrine opt-ins: the private entity manager, and the XML mapping with the private entity manager.
 */
final class LintContainerKernelTest extends KernelTestCase
{
    private static string $kernelClass = AuthKitTestKernel::class;

    protected static function getKernelClass(): string
    {
        return self::$kernelClass;
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function kernels(): iterable
    {
        yield 'default' => [AuthKitTestKernel::class];
        yield 'mfa (default doctrine)' => [MfaTestKernel::class];
        yield 'private entity manager' => [PrivateEmMfaTestKernel::class];
        yield 'xml mapping + private entity manager' => [XmlMfaTestKernel::class];
    }

    /**
     * @param class-string $kernel
     */
    #[DataProvider('kernels')]
    public function testTheContainerLints(string $kernel): void
    {
        self::$kernelClass = $kernel;
        $tester            = new CommandTester((new Application(self::bootKernel(['debug' => true])))->find('lint:container'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('The container was linted successfully', $tester->getDisplay());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::$kernelClass = AuthKitTestKernel::class;
    }
}
