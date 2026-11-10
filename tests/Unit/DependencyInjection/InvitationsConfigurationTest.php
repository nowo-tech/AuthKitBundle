<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Command\PurgeInvitationsCommand;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\InvitationsPass;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Invitation\InvitationMailerInterface;
use Nowo\AuthKitBundle\Invitation\MailerInvitationMailer;
use Nowo\AuthKitBundle\Invitation\NullInvitationMailer;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Invitation\Security\RoleInvitationsAccessChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;

/**
 * `profiles.<name>.invitations` (1.32): defaults (disabled, safe), validation (P1 empty roles, literal debug-only
 * allow_unauthenticated, base_uri, e-mail field, invite_only), wiring (per-profile checkers, purge command, mapping)
 * and the compile-time guards (mailer fallback, SecurityBundle, CSRF).
 */
final class InvitationsConfigurationTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    public function testDefaultsKeepInvitationsDisabled(): void
    {
        $container = $this->load([]);
        $profile   = $this->profile($container);

        self::assertSame('disabled', $profile['invitations']['mode']);
        self::assertSame(7, $profile['invitations']['ttl_days']);
        self::assertTrue($profile['invitations']['lock_email']);
        self::assertFalse($profile['invitations']['show_link_when_undelivered']);
        self::assertSame(['ROLE_ADMIN'], $profile['invitations']['security']['access_roles']);
        self::assertFalse($profile['invitations']['security']['allow_unauthenticated']);
        self::assertSame('/invitation/{token}', $profile['invitations']['routes']['accept']['path']);
        self::assertSame([], $container->getParameter('nowo_auth_kit.invitations.allow_unauthenticated'));
        self::assertFalse($container->hasDefinition(PurgeInvitationsCommand::class), 'No purge command without an enabled profile.');
    }

    public function testEnabledProfileRegistersItsCheckerAndTheEntities(): void
    {
        $container = $this->load($this->enabled(['security' => ['access_roles' => ['ROLE_HR']]]));

        self::assertTrue($container->hasDefinition(PurgeInvitationsCommand::class));
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertSame(['default' => false], $container->getParameter('nowo_auth_kit.invitations.allow_unauthenticated'));
        $checker = $container->getDefinition('nowo_auth_kit.invitations.access_checker.default');
        self::assertSame(RoleInvitationsAccessChecker::class, $checker->getClass());
        self::assertSame(['ROLE_HR'], $checker->getArgument(1));
        self::assertInstanceOf(Reference::class, $container->getDefinition(InvitationsAccessGuard::class)->getArgument('$checkers'));
        self::assertSame(['accept', 'index', 'new', 'resend', 'revoke', 'social'], array_keys($this->profile($container)['invitations']['routes']));
    }

    public function testCustomAccessCheckerIsUsed(): void
    {
        $container = $this->load($this->enabled(['security' => ['access_checker' => 'app.invitations_checker']]));

        self::assertFalse($container->hasDefinition('nowo_auth_kit.invitations.access_checker.default'));
    }

    public function testInviteOnlyModeIsAccepted(): void
    {
        $container = $this->load(['registration_mode' => 'invite_only'] + $this->enabled());

        self::assertSame('invite_only', $this->profile($container)['registration_mode']);
    }

    public function testDebugKernelAcceptsAllowUnauthenticated(): void
    {
        $container = $this->load($this->enabled(['security' => ['allow_unauthenticated' => true]]), true);

        self::assertSame(['default' => true], $container->getParameter('nowo_auth_kit.invitations.allow_unauthenticated'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'invite_only without invitations' => [['registration_mode' => 'invite_only', 'absolute_url' => ['base_uri' => 'https://app.example.com']], 'requires "invitations.mode: enabled"'];
        yield 'no base_uri' => [['invitations' => ['mode' => 'enabled']], 'requires "nowo_auth_kit.absolute_url.base_uri"'];
        yield 'no e-mail field' => [['registration_fields' => ['username', 'password']] + self::enabledConfig(), 'needs a registration field for the invited e-mail'];
        yield 'unknown e-mail field' => [self::enabledConfig(['email_field' => 'nope']), 'needs a registration field for the invited e-mail'];
        yield 'allow_unauthenticated without debug' => [self::enabledConfig(['security' => ['allow_unauthenticated' => true]]), 'refused when kernel.debug is false'];
        yield 'duplicate route name' => [self::enabledConfig(['routes' => ['index' => ['name' => 'nowo_auth_kit_login']]]), 'Duplicate route name "nowo_auth_kit_login"'];
        yield 'duplicate accept route name without admin routes' => [self::enabledConfig(['admin_routes' => false, 'routes' => ['accept' => ['name' => 'nowo_auth_kit_login']]]), 'Duplicate route name "nowo_auth_kit_login"'];
    }

    public function testHostKeysDefaultToThe132BehaviourAndAcceptValues(): void
    {
        $defaults = $this->profile($this->load($this->enabled()))['invitations'];
        self::assertNull($defaults['registration_role']);
        self::assertNull($defaults['merge_key']);
        self::assertSame(InvitationsConfiguration::PAYLOAD_MERGE_REPLACE, $defaults['payload_merge']);
        self::assertTrue($defaults['admin_routes']);

        // admin_routes: false leaves the admin route names free (no duplicate check on unregistered routes).
        $set = $this->profile($this->load($this->enabled([
            'registration_role' => 'ROLE_MEMBER',
            'merge_key'         => 'organization',
            'payload_merge'     => 'deep',
            'admin_routes'      => false,
            'routes'            => ['index' => ['name' => 'nowo_auth_kit_login'], 'accept' => ['path' => '/join/{token}']],
        ])))['invitations'];
        self::assertSame('/join/{token}', $set['routes']['accept']['path']);
        self::assertSame('ROLE_MEMBER', $set['registration_role']);
        self::assertSame('organization', $set['merge_key']);
        self::assertSame(InvitationsConfiguration::PAYLOAD_MERGE_DEEP, $set['payload_merge']);
        self::assertFalse($set['admin_routes']);
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
        yield 'empty access roles (P1)' => [['security' => ['access_roles' => []]], 'must list at least one role'];
        yield 'blank access role (P1)' => [['security' => ['access_roles' => [' ']]], 'must list at least one role'];
        yield 'not a ROLE_ name' => [['roles' => ['admin']], 'use ROLE_* names'];
        yield 'protocol-relative path' => [['routes' => ['accept' => ['path' => '//evil.example/{token}']]], 'must start with a single "/"'];
        yield 'backslash path' => [['routes' => ['index' => ['path' => '/\\evil']]], 'must start with a single "/"'];
        yield 'relative path' => [['routes' => ['index' => ['path' => 'admin']]], 'must start with a single "/"'];
        yield 'ttl too long' => [['ttl_days' => 91], 'ttl_days'];
        yield 'per_page not offered' => [['admin' => ['per_page' => 7]], 'per_page'];
        yield 'registration_role not a ROLE_ name' => [['registration_role' => 'member'], 'Invalid invitations.registration_role'];
        yield 'merge_key with a space' => [['merge_key' => 'org id'], 'Invalid invitations.merge_key'];
        yield 'unknown payload_merge' => [['payload_merge' => 'union'], 'payload_merge'];
        yield 'accept path without token' => [['routes' => ['accept' => ['path' => '/invitation']]], 'must end with "/{token}"'];
        yield 'accept path with token mid-path' => [['routes' => ['accept' => ['path' => '/invitation/{token}/accept']]], 'must end with "/{token}"'];
    }

    /**
     * @param array<string, mixed> $invitations
     */
    #[DataProvider('invalidTrees')]
    public function testInvalidTreeIsRefused(array $invitations, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(self::enabledConfig($invitations));
    }

    public function testAllowUnauthenticatedMustBeALiteralBoolean(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->registerExtension(new NowoAuthKitExtension());
        $container->loadFromExtension('nowo_auth_kit', ['user_class' => self::USER] + self::enabledConfig(['security' => ['allow_unauthenticated' => '%env(bool:ALLOW_INVITATIONS)%']]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a literal boolean');

        $container->compile();
    }

    public function testRegisterMappingAutoMapsTheEntitiesForInvitations(): void
    {
        self::assertTrue($this->load(['doctrine' => ['register_mapping' => 'auto']] + $this->enabled())->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertFalse($this->load(['doctrine' => ['register_mapping' => 'auto']])->getParameter('nowo_auth_kit.doctrine.register_mapping'));
    }

    public function testTtlChoicesIncludeTheDefaultSorted(): void
    {
        self::assertSame([1, 5, 30], InvitationsConfiguration::ttlChoices(['ttl_choices' => [30, 1, 'x'], 'ttl_days' => 5]));
        self::assertSame([7], InvitationsConfiguration::ttlChoices([]));
    }

    public function testInvitationsPassFallsBackToTheNullMailerWithoutSymfonyMailer(): void
    {
        $container = $this->load($this->enabled());
        $this->securityServices($container);
        (new InvitationsPass())->process($container);

        self::assertSame(NullInvitationMailer::class, (string) $container->getAlias(InvitationMailerInterface::class));
        self::assertFalse($container->hasDefinition(MailerInvitationMailer::class));
    }

    public function testInvitationsPassKeepsTheMailerWhenSymfonyMailerIsWired(): void
    {
        $container = $this->load($this->enabled());
        $this->securityServices($container);
        $container->setDefinition('mailer.mailer', new Definition(MailerInterface::class));
        (new InvitationsPass())->process($container);

        self::assertSame(MailerInvitationMailer::class, (string) $container->getAlias(InvitationMailerInterface::class));
    }

    public function testInvitationsPassLeavesAHostMailerAlone(): void
    {
        $container = $this->load($this->enabled());
        $this->securityServices($container);
        $container->setAlias(InvitationMailerInterface::class, 'app.invitation_mailer');
        (new InvitationsPass())->process($container);

        self::assertSame('app.invitation_mailer', (string) $container->getAlias(InvitationMailerInterface::class));
    }

    public function testInvitationsPassRequiresSecurityBundle(): void
    {
        $container = $this->load($this->enabled());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.invitations needs SecurityBundle');

        (new InvitationsPass())->process($container);
    }

    public function testInvitationsPassAcceptsAllowUnauthenticatedWithoutSecurityBundle(): void
    {
        $container = $this->load($this->enabled(['security' => ['allow_unauthenticated' => true]]), true);
        (new InvitationsPass())->process($container);

        self::assertTrue($container->hasDefinition(PurgeInvitationsCommand::class));
    }

    public function testInvitationsPassIgnoresMissingOrInvalidProfiles(): void
    {
        $container = new ContainerBuilder();
        (new InvitationsPass())->process($container);
        $container->setParameter('nowo_auth_kit.profiles', 'nope');
        (new InvitationsPass())->process($container);
        $container->setParameter('nowo_auth_kit.profiles', ['broken' => 'nope', 'off' => ['invitations' => ['mode' => 'disabled']]]);
        (new InvitationsPass())->process($container);

        self::assertFalse($container->hasAlias(InvitationMailerInterface::class));
    }

    public function testCsrfPassRefusesInvitationsWithoutFormCsrf(): void
    {
        $container = $this->load($this->enabled());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.invitations requires CSRF protection');

        (new CsrfProtectionPass())->process($container);
    }

    /**
     * @param array<string, mixed> $invitations
     *
     * @return array<string, mixed>
     */
    private static function enabledConfig(array $invitations = []): array
    {
        return [
            'absolute_url' => ['base_uri' => 'https://app.example.com'],
            'invitations'  => ['mode' => 'enabled'] + $invitations,
        ];
    }

    /**
     * @param array<string, mixed> $invitations
     *
     * @return array<string, mixed>
     */
    private function enabled(array $invitations = []): array
    {
        return self::enabledConfig($invitations);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, bool $debug = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
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

    private function securityServices(ContainerBuilder $container): void
    {
        $container->setDefinition('security.token_storage', new Definition('stdClass'));
        $container->setDefinition('security.authorization_checker', new Definition('stdClass'));
    }
}
