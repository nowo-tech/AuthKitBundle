<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The 1.42 keys: `attempt_limiter.cache_pool`, `invitations.batch`, `invitations.social` (defaults unchanged, the
 * social route and callback handler only when opted in, and their validation).
 */
final class InvitationFollowupsConfigurationTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    private const SOCIAL_TAG = 'nowo_auth_kit.social_callback_handler';

    public function testDefaultsKeepThe141Behaviour(): void
    {
        $container   = $this->load($this->enabled());
        $invitations = $this->profile($container)['invitations'];

        self::assertSame(['max_size' => 0, 'rate_limit' => 3, 'rate_window' => 3600], $invitations['batch']);
        self::assertSame(['accept_page' => false, 'name_field' => null, 'keep_pending_on_mismatch' => false], $invitations['social']);
        self::assertSame(['path' => '/invitation/social/{provider}', 'name' => 'nowo_auth_kit_invitation_social'], $invitations['routes']['social']);
        $cache = $container->getDefinition(AuthKitAttemptLimiter::class)->getArgument('$cache');
        self::assertInstanceOf(Reference::class, $cache);
        self::assertSame('cache.app', (string) $cache);
        self::assertFalse($container->getDefinition(InvitationAcceptController::class)->hasTag(self::SOCIAL_TAG), 'No callback handler unless opted in.');
    }

    public function testTheCountersUseTheConfiguredPool(): void
    {
        $container = $this->load(['attempt_limiter' => ['cache_pool' => 'cache.rate_limits']]);

        self::assertSame('cache.rate_limits', (string) $container->getDefinition(AuthKitAttemptLimiter::class)->getArgument('$cache'));
    }

    public function testTheSocialAcceptPageTagsTheCallbackHandler(): void
    {
        $container = $this->load(['social_login' => ['mode' => 'enabled']] + $this->enabled(['social' => ['accept_page' => true, 'name_field' => 'nickname']], [
            'email',
            'password',
            ['name' => 'nickname', 'required' => false],
        ]));

        self::assertTrue($container->getDefinition(InvitationAcceptController::class)->hasTag(self::SOCIAL_TAG));
        self::assertSame('nickname', $this->profile($container)['invitations']['social']['name_field']);
    }

    public function testTheSocialRouteNameCountsOnlyWhenRegistered(): void
    {
        // Off: the default social route name may be reused by the host.
        $this->load($this->enabled(['routes' => ['social' => ['name' => 'nowo_auth_kit_login']]]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowo_auth_kit_login');
        $this->load(['social_login' => ['mode' => 'enabled']] + $this->enabled(['social' => ['accept_page' => true], 'routes' => ['social' => ['name' => 'nowo_auth_kit_login']]]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'accept page without social login' => [self::enabledConfig(['social' => ['accept_page' => true]]), 'requires "social_login.mode: enabled"'];
        yield 'accept page with deny (KL-L3)' => [['social_login' => ['mode' => 'enabled', 'link_existing_account' => 'deny']] + self::enabledConfig(['social' => ['accept_page' => true]]), 'cannot be used with "social_login.link_existing_account: deny"'];
        yield 'name field unknown' => [self::enabledConfig(['social' => ['name_field' => 'nickname']]), '"nickname" is not one'];
        yield 'name field is the password' => [self::enabledConfig(['social' => ['name_field' => 'password']]), '"password" is not one'];
        yield 'name field not mapped' => [self::enabledConfig(['social' => ['name_field' => 'terms']], ['email', 'password', ['name' => 'terms', 'type' => 'checkbox', 'mapped' => false]]), '"terms" is not one'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->load($config);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTrees(): iterable
    {
        yield 'social path without provider' => [['invitations' => ['routes' => ['social' => ['path' => '/invitation/social']]]], 'must end with "/{provider}"'];
        yield 'batch too large' => [['invitations' => ['batch' => ['max_size' => 501]]], 'max_size'];
        yield 'batch rate limit zero' => [['invitations' => ['batch' => ['rate_limit' => 0]]], 'rate_limit'];
        yield 'empty cache pool' => [['attempt_limiter' => ['cache_pool' => '']], 'cache_pool'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidTrees')]
    public function testInvalidTreeIsRefused(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load($config);
    }

    /**
     * @param array<string, mixed> $invitations
     * @param list<mixed>|null $fields
     *
     * @return array<string, mixed>
     */
    private static function enabledConfig(array $invitations = [], ?array $fields = null): array
    {
        $config = [
            'absolute_url' => ['base_uri' => 'https://app.example.com'],
            'invitations'  => ['mode' => 'enabled'] + $invitations,
        ];
        if ($fields !== null) {
            $config['registration_fields'] = $fields;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $invitations
     * @param list<mixed>|null $fields
     *
     * @return array<string, mixed>
     */
    private function enabled(array $invitations = [], ?array $fields = null): array
    {
        return self::enabledConfig($invitations, $fields);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new NowoAuthKitExtension())->load([['user_class' => self::USER] + $config], $container);

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(ContainerBuilder $container): array
    {
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');

        return $profiles['default'];
    }
}
