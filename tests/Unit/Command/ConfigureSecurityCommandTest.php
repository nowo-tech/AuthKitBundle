<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Command;

use Nowo\AuthKitBundle\Command\ConfigureSecurityCommand;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

use function count;

final class ConfigureSecurityCommandTest extends TestCase
{
    private string $testDir;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->testDir    = sys_get_temp_dir() . '/auth_kit_cmd_' . uniqid();
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    public function testFailsWhenSecurityYamlMissing(): void
    {
        $tester   = new CommandTester($this->createCommand('disabled', 'demo_home'));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('security.yaml not found', $tester->getDisplay());
    }

    public function testUpdatesSecurityYaml(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester   = new CommandTester($this->createCommand('disabled', 'demo_home'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('nowo_auth_kit_login', $security['security']['firewalls']['main']['form_login']['login_path']);
        self::assertSame('login_form[_username]', $security['security']['firewalls']['main']['form_login']['username_parameter']);
        self::assertSame('App\\Entity\\User', $security['security']['providers']['app_user_provider']['entity']['class']);
        self::assertArrayNotHasKey('remember_me', $security['security']['firewalls']['main']);
    }

    public function testAddsRememberMeWhenEnabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', 'demo_home', false, null, true));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('login_form[_remember_me]', $security['security']['firewalls']['main']['remember_me']['remember_me_parameter']);
        self::assertSame(604800, $security['security']['firewalls']['main']['remember_me']['lifetime']);
    }

    public function testSkipsFormLoginWhenAlreadyConfigured(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls' => [
                        'main' => [
                            'form_login' => ['login_path' => 'custom_login'],
                        ],
                    ],
                ],
            ], 2),
        );

        $tester   = new CommandTester($this->createCommand('disabled'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('already has form_login', $tester->getDisplay());

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('custom_login', $security['security']['firewalls']['main']['form_login']['login_path']);
    }

    public function testRemovesRememberMeWhenDisabledEvenIfFormLoginExists(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls' => [
                        'main' => [
                            'form_login'  => ['login_path' => 'custom_login'],
                            'remember_me' => [
                                'secret'                => '%kernel.secret%',
                                'lifetime'              => 604800,
                                'path'                  => '/',
                                'remember_me_parameter' => 'login_form[_remember_me]',
                            ],
                        ],
                    ],
                ],
            ], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('custom_login', $security['security']['firewalls']['main']['form_login']['login_path']);
        self::assertArrayNotHasKey('remember_me', $security['security']['firewalls']['main']);
    }

    public function testAddsRememberMeWhenEnabledEvenIfFormLoginExists(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls' => [
                        'main' => [
                            'form_login' => ['login_path' => 'custom_login'],
                        ],
                    ],
                ],
            ], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', 'demo_home', false, null, true));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('custom_login', $security['security']['firewalls']['main']['form_login']['login_path']);
        self::assertSame('login_form[_remember_me]', $security['security']['firewalls']['main']['remember_me']['remember_me_parameter']);
    }

    public function testConfigureDefinesForceOptionAndHelp(): void
    {
        $command = $this->createCommand('disabled');

        self::assertTrue($command->getDefinition()->hasOption('force'));
        self::assertStringContainsString('security.yaml', $command->getHelp());
    }

    public function testAddsResetAccessControlWhenEnabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('enabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertGreaterThanOrEqual(5, count($security['security']['access_control']));
    }

    public function testAddsMagicLoginLinkAndAccessControlWhenEnabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', magicLoginMode: 'enabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('nowo_auth_kit_magic_login_check', $security['security']['firewalls']['main']['login_link']['check_route']);
        self::assertSame(['email'], $security['security']['firewalls']['main']['login_link']['signature_properties']);
        self::assertSame(600, $security['security']['firewalls']['main']['login_link']['lifetime']);
        self::assertArrayNotHasKey('check_post_only', $security['security']['firewalls']['main']['login_link']);
        $paths = array_column($security['security']['access_control'], 'path');
        self::assertContains('^\/magic\-login', $paths);
        self::assertContains('^\/magic\-login\/check', $paths);
    }

    public function testMagicLoginConfirmInterstitialSetsCheckPostOnly(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand(
            'disabled',
            magicLoginMode: 'enabled',
            magicLoginConfirmInterstitial: true,
        ));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertTrue($security['security']['firewalls']['main']['login_link']['check_post_only']);
    }

    public function testMagicLoginLinkIncludesDefaultTargetPath(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', 'demo_home', magicLoginMode: 'enabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('demo_home', $security['security']['firewalls']['main']['login_link']['default_target_path']);
    }

    public function testRemovesLoginLinkWhenMagicLoginDisabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls' => [
                        'main' => [
                            'form_login' => [
                                'login_path' => 'nowo_auth_kit_login',
                                'check_path' => 'nowo_auth_kit_login',
                            ],
                            'login_link' => [
                                'check_route' => 'nowo_auth_kit_magic_login_check',
                            ],
                        ],
                    ],
                ],
            ], 6),
        );

        $tester = new CommandTester($this->createCommand('disabled', magicLoginMode: 'disabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertArrayNotHasKey('login_link', $security['security']['firewalls']['main']);
    }

    public function testAddsLocalePrefixedAccessControlWhenEnabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', null, true));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('^/(en|es)\/login', $security['security']['access_control'][0]['path']);
    }

    public function testBothLocaleModeAddsLocalizedAndBareAccessControl(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', null, 'both'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $paths    = array_column($security['security']['access_control'], 'path');

        self::assertContains('^/(en|es)\/login', $paths);
        self::assertContains('^\/login', $paths);
        self::assertContains('^/(en|es)\/register', $paths);
        self::assertContains('^\/register', $paths);
    }

    public function testSkipsDuplicateAccessControlRules(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls'      => ['main' => []],
                    'access_control' => [
                        ['path' => '^\/login', 'roles' => 'PUBLIC_ACCESS'],
                    ],
                ],
            ], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertCount(2, $security['security']['access_control']);
    }

    public function testUsesProjectDirFallbackWhenNull(): void
    {
        $originalDir = getcwd();
        self::assertNotFalse($originalDir);

        chdir($this->testDir);
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        try {
            $tester   = new CommandTester($this->createCommand('disabled', null, false));
            $exitCode = $tester->execute([]);

            self::assertSame(0, $exitCode);
        } finally {
            chdir($originalDir);
        }
    }

    public function testAddsSocialLoginPublicAccessWhenEnabled(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump(['security' => ['firewalls' => ['main' => []]]], 2),
        );

        $tester = new CommandTester($this->createCommand('disabled', null, false, null, false, 'disabled', 'enabled'));
        self::assertSame(0, $tester->execute([]));

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $paths    = array_column($security['security']['access_control'], 'path');
        self::assertContains('^\/login\/social\/[^/]+', $paths);
        self::assertContains('^\/login\/social\/[^/]+\/check', $paths);
    }

    public function testForceOverwritesFormLogin(): void
    {
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            Yaml::dump([
                'security' => [
                    'firewalls' => [
                        'main' => [
                            'form_login' => ['login_path' => 'custom_login'],
                        ],
                    ],
                ],
            ], 2),
        );

        $tester   = new CommandTester($this->createCommand('disabled'));
        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(0, $exitCode);
        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        self::assertSame('nowo_auth_kit_login', $security['security']['firewalls']['main']['form_login']['login_path']);
    }

    private function createCommand(
        string $passwordResetMode,
        ?string $loginSuccessRoute = null,
        string|bool $localeInPath = false,
        ?string $projectDir = null,
        bool $rememberMeEnabled = false,
        string $magicLoginMode = 'disabled',
        string $socialLoginMode = 'disabled',
        bool $magicLoginConfirmInterstitial = false,
    ): ConfigureSecurityCommand {
        return new ConfigureSecurityCommand(
            $projectDir ?? $this->testDir,
            $this->routes(),
            'main',
            'App\\Entity\\User',
            'email',
            $loginSuccessRoute,
            $passwordResetMode,
            new AuthKitRouteLocaleParameters(new RequestStack(), $localeInPath, 'en', ['en', 'es']),
            [
                ['name' => '_username', 'type' => 'text', 'property' => 'email', 'hash' => false, 'required' => true, 'security_name' => '_username'],
                ['name' => '_password', 'type' => 'password', 'property' => null, 'hash' => false, 'required' => true, 'security_name' => '_password'],
                ...($rememberMeEnabled ? [['name' => '_remember_me', 'type' => 'checkbox', 'property' => null, 'hash' => false, 'required' => false, 'security_name' => '_remember_me']] : []),
            ],
            [
                'enabled'  => $rememberMeEnabled,
                'lifetime' => 604800,
                'path'     => '/',
            ],
            [
                'mode'                 => $magicLoginMode,
                'lifetime'             => 600,
                'max_uses'             => 1,
                'confirm_interstitial' => $magicLoginConfirmInterstitial,
            ],
            $socialLoginMode,
        );
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        return [
            'login'               => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'              => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register'            => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
            'reset_request'       => ['path' => '/reset-password', 'name' => 'nowo_auth_kit_reset_password_request'],
            'reset_password'      => ['path' => '/reset-password/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
            'reset_password_code' => ['path' => '/reset-password/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
            'magic_login_request' => ['path' => '/magic-login', 'name' => 'nowo_auth_kit_magic_login_request'],
            'magic_login_check'   => ['path' => '/magic-login/check', 'name' => 'nowo_auth_kit_magic_login_check'],
            'magic_login_confirm' => ['path' => '/magic-login/confirm', 'name' => 'nowo_auth_kit_magic_login_confirm'],
            'social_login_start'  => ['path' => '/login/social/{provider}', 'name' => 'nowo_auth_kit_social_login_start'],
            'social_login_check'  => ['path' => '/login/social/{provider}/check', 'name' => 'nowo_auth_kit_social_login_check'],
        ];
    }
}
