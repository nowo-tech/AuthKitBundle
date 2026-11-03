<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Environment;

/**
 * The MFA kernel with UserKit 1.11 sessions and the account switcher (1.34): a `/accounts-menu` page renders the
 * switcher partial, `ROLE_DISABLED` accounts are refused by the user checker.
 */
class MultiAccountTestKernel extends MfaTestKernel
{
    public const NAME = 'multi-account';

    /** @var array<string, mixed> extra `multi_account` keys */
    protected const MULTI_ACCOUNT = [];

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoUserKitBundle();
    }

    /**
     * `/accounts-menu`: the switcher partial of the current browser.
     */
    public function menu(): Response
    {
        $tests = $this->getContainer()->get('test.service_container');
        $twig  = $tests instanceof ContainerInterface ? $tests->get('twig') : null;
        if (!$twig instanceof Environment) {
            return new Response('', 500);
        }

        return new Response($twig->createTemplate("{{ include('@NowoAuthKitBundle/accounts/_switcher.html.twig', {target_path: '/mfa-home'}) }}")->render());
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', ['multi_account' => static::MULTI_ACCOUNT + ['enabled' => true, 'after_switch' => 'mfa_home']]);
        $container->loadFromExtension('nowo_user_kit', [
            'user_class' => KernelUser::class,
            'sessions'   => ['enabled' => true, 'remember_me_epoch' => ['enabled' => true]],
        ]);
        $container->loadFromExtension('security', ['firewalls' => ['main' => ['user_checker' => DisabledRoleUserChecker::class]]]);
        $container->register(DisabledRoleUserChecker::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('accounts_menu', '/accounts-menu')->controller('kernel::menu');
    }
}
