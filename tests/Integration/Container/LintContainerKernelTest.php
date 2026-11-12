<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Container;

use Nowo\AuthKitBundle\Tests\Integration\Ddd\DddHostTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\ResetTableTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager\PrivateEmMfaTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\XmlMapping\XmlMfaTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Kernel;

/**
 * `lint:container` (argument types of every service definition) on the default configuration and with the 1.46.0
 * opt-ins: the private entity manager, the XML mapping with the private entity manager, the reset table, and the DDD
 * host kernel (framework-free user / step-up ports served through the gates).
 */
final class LintContainerKernelTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<Kernel>}>
     */
    public static function kernels(): iterable
    {
        yield 'default' => [AuthKitTestKernel::class];
        yield 'mfa (default doctrine)' => [MfaTestKernel::class];
        yield 'private entity manager' => [PrivateEmMfaTestKernel::class];
        yield 'xml mapping + private entity manager' => [XmlMfaTestKernel::class];
        yield 'password reset table' => [ResetTableTestKernel::class];
        yield 'DDD host: framework-free ports, reset table, xml, private entity manager' => [DddHostTestKernel::class];
    }

    /**
     * @param class-string<Kernel> $class
     */
    #[DataProvider('kernels')]
    public function testTheContainerLints(string $class): void
    {
        $kernel = new $class('test', true);
        $kernel->boot();
        try {
            $tester = new CommandTester((new Application($kernel))->find('lint:container'));

            self::assertSame(0, $tester->execute([]), $tester->getDisplay());
            self::assertStringContainsString('The container was linted successfully', $tester->getDisplay());
        } finally {
            $kernel->shutdown();
        }
    }
}
