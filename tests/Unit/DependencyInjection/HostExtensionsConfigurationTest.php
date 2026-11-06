<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Defaults of the 1.37 options: nothing changes for a host that sets nothing.
 */
final class HostExtensionsConfigurationTest extends TestCase
{
    public function testDefaultsLeaveEverythingOff(): void
    {
        $profile = $this->process([])['profiles']['default'];

        self::assertNull($profile['magic_login']['availability_checker']);
        self::assertNull($profile['password_reset']['availability_checker']);
        self::assertSame('@NowoAuthKitBundle/mfa/account.html.twig', $profile['web_ui']['templates']['mfa_account']);
        self::assertSame('@NowoAuthKitBundle/mfa/account_codes.html.twig', $profile['web_ui']['templates']['mfa_account_codes']);
    }

    public function testCheckersAndTemplatesAreConfigurable(): void
    {
        $profile = $this->process([
            'magic_login'    => ['availability_checker' => 'app.magic'],
            'password_reset' => ['availability_checker' => 'app.reset'],
            'web_ui'         => ['templates' => ['mfa_account' => 'host/a.html.twig', 'mfa_account_codes' => 'host/b.html.twig']],
        ])['profiles']['default'];

        self::assertSame('app.magic', $profile['magic_login']['availability_checker']);
        self::assertSame('app.reset', $profile['password_reset']['availability_checker']);
        self::assertSame('host/a.html.twig', $profile['web_ui']['templates']['mfa_account']);
        self::assertSame('host/b.html.twig', $profile['web_ui']['templates']['mfa_account_codes']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidCheckers(): iterable
    {
        yield 'empty magic' => ['magic_login', ''];
        yield 'empty reset' => ['password_reset', ''];
        yield 'not a string' => ['magic_login', 12];
    }

    #[DataProvider('invalidCheckers')]
    public function testInvalidCheckerIdIsRefused(string $feature, mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('availability_checker must be a service id or null');

        $this->process([$feature => ['availability_checker' => $value]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => TestUser::class] + $config]);
    }
}
