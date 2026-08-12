<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Command;

use Nowo\AuthKitBundle\Config\RememberMeConfigResolver;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Security\AuthKitFormLoginParameters;
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
 * Merges form_login, logout, optional login_link, and access_control entries into security.yaml.
 */
#[AsCommand(
    name: 'nowo:auth-kit:configure-security',
    description: 'Configures security.yaml for AuthKit login and logout routes',
)]
final class ConfigureSecurityCommand extends Command
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<array{name: string, type: string, property: ?string, hash: bool, required: bool, security_name: ?string}> $loginFields
     * @param array{enabled: bool, lifetime: int, path: string} $rememberMe
     * @param array{mode: string, lifetime: int, max_uses: int, confirm_interstitial?: bool} $magicLogin
     */
    public function __construct(
        private readonly ?string $projectDir,
        private readonly array $routes,
        private readonly string $firewall,
        private readonly string $userClass,
        private readonly string $userIdentifierField,
        private readonly ?string $loginSuccessRoute,
        private readonly string $passwordResetMode,
        private readonly AuthKitRouteLocaleParameters $routeLocaleParameters,
        private readonly array $loginFields,
        private readonly array $rememberMe,
        private readonly array $magicLogin,
        private readonly string $socialLoginMode = 'disabled',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing form_login configuration on the target firewall')
            ->setHelp('Reads nowo_auth_kit.yaml and updates config/packages/security.yaml with provider, form_login (including nested field names), optional remember_me and login_link, logout, and access_control.');
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
                'login_path' => $loginRoute,
                'check_path' => $loginRoute,
                ...AuthKitFormLoginParameters::formLoginOptions(),
            ];

            if ($this->loginSuccessRoute !== null) {
                $formLogin['default_target_path'] = $this->loginSuccessRoute;
            }

            $firewall['form_login'] = $formLogin;
            $firewall['logout']     = [
                'path'               => $logoutRoute,
                'target'             => $this->loginSuccessRoute ?? $loginRoute,
                'invalidate_session' => true,
                'enable_csrf'        => true,
            ];

            $firewall['provider'] = 'app_user_provider';
            $firewall['lazy']     = true;
        }

        $this->syncRememberMe($firewall);
        $this->syncLoginLink($firewall);

        $accessControl = $security['security']['access_control'] ?? [];
        $publicPaths   = [];
        foreach ([
            $loginPath,
            $registerPath,
        ] as $path) {
            foreach ($this->routeLocaleParameters->accessControlPatterns($path) as $pattern) {
                $publicPaths[] = ['path' => $pattern, 'roles' => 'PUBLIC_ACCESS'];
            }
        }

        if ($this->passwordResetMode === 'enabled') {
            $resetPaths = [$this->routes['reset_request']['path']];
            if (isset($this->routes['reset_password']['path'])) {
                $resetPaths[] = $this->routes['reset_password']['path'];
            }
            if (isset($this->routes['reset_password_code']['path'])) {
                $resetPaths[] = $this->routes['reset_password_code']['path'];
            }

            foreach ($resetPaths as $path) {
                foreach ($this->routeLocaleParameters->accessControlPatterns($path) as $pattern) {
                    $publicPaths[] = ['path' => $pattern, 'roles' => 'PUBLIC_ACCESS'];
                }
            }
        }

        if ($this->magicLogin['mode'] === 'enabled') {
            $magicPaths = [
                $this->routes['magic_login_request']['path'],
                $this->routes['magic_login_check']['path'],
            ];
            if (($this->magicLogin['confirm_interstitial'] ?? false) === true) {
                $magicPaths[] = $this->routes['magic_login_confirm']['path'];
            }

            foreach ($magicPaths as $path) {
                foreach ($this->routeLocaleParameters->accessControlPatterns($path) as $pattern) {
                    $publicPaths[] = ['path' => $pattern, 'roles' => 'PUBLIC_ACCESS'];
                }
            }
        }

        if ($this->socialLoginMode === 'enabled') {
            foreach ([
                $this->routes['social_login_start']['path'] ?? '/login/social/{provider}',
                $this->routes['social_login_check']['path'] ?? '/login/social/{provider}/check',
            ] as $path) {
                foreach ($this->routeLocaleParameters->accessControlPatterns($path) as $pattern) {
                    $publicPaths[] = ['path' => $pattern, 'roles' => 'PUBLIC_ACCESS'];
                }
            }
        }

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
            'form_login uses nested parameters (login_form[_username]) — see AuthKitFormLoginParameters.',
            'remember_me and login_link are synced on every run; use --force to refresh form_login and logout.',
            'Import bundle routes: config/routes/nowo_auth_kit.yaml',
            'Override templates: templates/bundles/NowoAuthKitBundle/security/',
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $firewall
     */
    private function syncRememberMe(array &$firewall): void
    {
        if (RememberMeConfigResolver::isFirewallEnabledForNormalizedFields((bool) $this->rememberMe['enabled'], $this->loginFields)) {
            $firewall['remember_me'] = AuthKitFormLoginParameters::rememberMeOptions(
                (int) $this->rememberMe['lifetime'],
                (string) $this->rememberMe['path'],
            );

            return;
        }

        unset($firewall['remember_me']);
    }

    /**
     * @param array<string, mixed> $firewall
     */
    private function syncLoginLink(array &$firewall): void
    {
        if ($this->magicLogin['mode'] !== 'enabled') {
            unset($firewall['login_link']);

            return;
        }

        $loginLink = [
            'check_route'          => $this->routes['magic_login_check']['name'],
            'signature_properties' => [$this->userIdentifierField],
            'lifetime'             => (int) $this->magicLogin['lifetime'],
            'max_uses'             => (int) $this->magicLogin['max_uses'],
        ];

        if (($this->magicLogin['confirm_interstitial'] ?? false) === true) {
            $loginLink['check_post_only'] = true;
        }

        if ($this->loginSuccessRoute !== null) {
            $loginLink['default_target_path'] = $this->loginSuccessRoute;
        }

        $firewall['login_link'] = $loginLink;
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
