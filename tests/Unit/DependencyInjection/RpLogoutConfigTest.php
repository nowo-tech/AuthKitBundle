<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\EnterpriseSecretEncryptionGuard;
use Nowo\AuthKitBundle\EventSubscriber\RpInitiatedLogoutSubscriber;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function json_encode;

/**
 * 2.8.0: `social_login.rp_logout.post_logout_redirect_uris` and `doctrine.require_enterprise_secret_encryption`.
 */
final class RpLogoutConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame([], $this->socialLogin($container)['rp_logout']['post_logout_redirect_uris']);
        self::assertTrue($container->getDefinition(EnterpriseSecretEncryptionGuard::class)->hasTag('doctrine.event_listener'));
        self::assertSame('%kernel.secret%', $container->getDefinition(RpLogoutSession::class)->getArgument('$secret'));
        self::assertTrue($container->hasDefinition(RpInitiatedLogoutSubscriber::class));
    }

    public function testAnAllowListIsKeptInOrderAndTheGuardCanBeTurnedOffForTests(): void
    {
        $container = $this->load([
            'social_login' => ['rp_logout' => ['post_logout_redirect_uris' => ['/signed-out', 'https://app.example.com/']]],
            'doctrine'     => ['require_enterprise_secret_encryption' => false],
        ]);

        self::assertSame(['/signed-out', 'https://app.example.com/'], $this->socialLogin($container)['rp_logout']['post_logout_redirect_uris']);
        self::assertFalse($container->hasDefinition(EnterpriseSecretEncryptionGuard::class));
    }

    public function testAnInvalidEntryIsRefused(): void
    {
        foreach (['//evil.example/', 'javascript:alert(1)', 'https://app.example.com/#x', 'bye'] as $entry) {
            try {
                $this->load(['social_login' => ['rp_logout' => ['post_logout_redirect_uris' => [$entry]]]]);
                self::fail('refused: ' . json_encode($entry));
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('post_logout_redirect_uris', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function socialLogin(ContainerBuilder $container): array
    {
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        self::assertIsArray($profiles);
        $profile = $profiles['default'] ?? null;
        self::assertTrue(is_array($profile) && is_array($profile['social_login'] ?? null));

        /** @var array<string, mixed> $socialLogin */
        $socialLogin = $profile['social_login'];

        return $socialLogin;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
