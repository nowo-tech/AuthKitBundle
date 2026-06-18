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
        self::assertSame('App\\Entity\\User', $security['security']['providers']['app_user_provider']['entity']['class']);
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
        bool $localeInPath = false,
        ?string $projectDir = null,
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
        ];
    }
}
