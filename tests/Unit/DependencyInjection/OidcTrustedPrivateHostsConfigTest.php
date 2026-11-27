<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function json_encode;

/**
 * 2.9.0: `oidc.trusted_private_hosts` (relaxes the SSRF guard of the OIDC fetches) is honoured only when
 * kernel.environment is "test"; any other environment refuses a non-empty list at compile time.
 */
final class OidcTrustedPrivateHostsConfigTest extends TestCase
{
    public function testDefaultIsEmptyInEveryEnvironment(): void
    {
        foreach (['prod', 'dev', 'test', null] as $environment) {
            self::assertSame([], $this->load([], $environment)->getParameter('nowo_auth_kit.oidc.trusted_private_hosts'));
        }
    }

    public function testExactHostsAreKeptLowerCasedInTheTestEnvironment(): void
    {
        $container = $this->load(['oidc' => ['trusted_private_hosts' => ['Mock-IdP.e2e.test', 'mock-idp.e2e.test', 'idp']]], 'test');

        self::assertSame(['mock-idp.e2e.test', 'idp'], $container->getParameter('nowo_auth_kit.oidc.trusted_private_hosts'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function nonTestEnvironments(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'dev' => ['dev'];
        yield 'staging' => ['staging'];
        yield 'no environment parameter' => [null];
    }

    /**
     * SECURITY: the list can never be enabled outside the test environment by accident.
     */
    #[DataProvider('nonTestEnvironments')]
    public function testANonEmptyListIsRefusedOutsideTheTestEnvironment(?string $environment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('honoured only when kernel.environment is "test"');
        $this->load(['oidc' => ['trusted_private_hosts' => ['mock-idp.e2e.test']]], $environment);
    }

    /**
     * SECURITY: exact host names only: wildcards, IP addresses and ranges, ports and URLs are refused.
     */
    public function testAnythingButAnExactHostNameIsRefused(): void
    {
        foreach (['*.e2e.test', '*', 'mock-*.test', '10.0.0.5', '127.0.0.1', '::1', '10.0.0.0/8', 'idp.test:8443', 'https://idp.test', 'idp.test.', '', '-idp.test', 'idp_test'] as $entry) {
            try {
                $this->load(['oidc' => ['trusted_private_hosts' => [$entry]]], 'test');
                self::fail('refused: ' . json_encode($entry));
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('trusted_private_hosts', $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, ?string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        if ($environment !== null) {
            $container->setParameter('kernel.environment', $environment);
        }
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
