<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use function sprintf;

/**
 * Merges form_login, logout, and access_control entries into security.yaml.
 */
#[AsCommand(
    name: 'nowo:auth-kit:configure-security',
    description: 'Configures security.yaml for AuthKit login and logout routes',
)]
final class ConfigureSecurityCommand extends Command
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly ?string $projectDir,
        private readonly array $routes,
        private readonly string $firewall,
        private readonly string $userClass,
        private readonly string $userIdentifierField,
        private readonly ?string $loginSuccessRoute,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing form_login configuration on the target firewall')
            ->setHelp('Reads nowo_auth_kit.yaml and updates config/packages/security.yaml with provider, form_login, logout, and access_control.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $filesystem   = new Filesystem();
        $projectDir   = $this->projectDir ?? (getcwd() ?: '.');
        $securityPath = $projectDir . '/config/packages/security.yaml';

        if (!is_file($securityPath)) {
            $io->error(sprintf('security.yaml not found at %s', $securityPath));

            return Command::FAILURE;
        }

        /** @var array<string, mixed> $security */
        $security = Yaml::parseFile($securityPath) ?: [];
        $security['security'] ??= [];

        $loginRoute   = $this->routes['login']['name'];
        $logoutRoute  = $this->routes['logout']['name'];
        $registerPath = $this->routes['register']['path'];
        $loginPath    = $this->routes['login']['path'];

        $security['security']['password_hashers'] ??= [
            'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => 'auto',
        ];

        $security['security']['providers'] ??= [];
        $security['security']['providers']['app_user_provider'] = [
            'entity' => [
                'class'    => $this->userClass,
                'property' => $this->userIdentifierField,
            ],
        ];

        $security['security']['firewalls'] ??= [];
        $security['security']['firewalls'][$this->firewall] ??= [
            'lazy'     => true,
            'provider' => 'app_user_provider',
        ];

        $firewall = &$security['security']['firewalls'][$this->firewall];

        if (isset($firewall['form_login']) && !$input->getOption('force')) {
            $io->warning(sprintf('Firewall "%s" already has form_login. Use --force to overwrite.', $this->firewall));
        } else {
            $formLogin = [
                'login_path'  => $loginRoute,
                'check_path'  => $loginRoute,
                'enable_csrf' => true,
            ];

            if ($this->loginSuccessRoute !== null) {
                $formLogin['default_target_path'] = $this->loginSuccessRoute;
            }

            $firewall['form_login'] = $formLogin;
            $firewall['logout']     = [
                'path'   => $logoutRoute,
                'target' => $loginRoute,
            ];
            $firewall['provider'] = 'app_user_provider';
            $firewall['lazy']     = true;
        }

        $accessControl = $security['security']['access_control'] ?? [];
        $publicPaths   = [
            ['path' => '^' . preg_quote($loginPath, '/'), 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^' . preg_quote($registerPath, '/'), 'roles' => 'PUBLIC_ACCESS'],
        ];

        foreach ($publicPaths as $rule) {
            if (!$this->accessControlContains($accessControl, $rule['path'])) {
                $accessControl[] = $rule;
            }
        }

        $security['security']['access_control'] = $accessControl;

        $filesystem->dumpFile($securityPath, Yaml::dump($security, 6, 2));

        $io->success(sprintf('Updated %s for firewall "%s".', $securityPath, $this->firewall));
        $io->note([
            'Verify user_class and user_identifier_field match your entity.',
            'Import bundle routes: config/routes/nowo_auth_kit.yaml',
            'Override templates: templates/bundles/NowoAuthKitBundle/security/',
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, string>> $accessControl
     */
    private function accessControlContains(array $accessControl, string $path): bool
    {
        foreach ($accessControl as $rule) {
            if (($rule['path'] ?? '') === $path) {
                return true;
            }
        }

        return false;
    }
}
