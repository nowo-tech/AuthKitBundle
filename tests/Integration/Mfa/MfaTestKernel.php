<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Twig\Environment;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with MFA enabled (1.34): remember-me, switch_user, `ROLE_ADMIN` required (grace 7 days), the
 * admin reset page, magic login, QR login, a protected `/mfa-home` page that prints the user and its token class.
 */
class MfaTestKernel extends AuthKitTestKernel
{
    /** @var array<string, mixed> extra `mfa` keys */
    protected const MFA = [];

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . static::NAME . '-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . static::NAME . '-' . $this->environment . '/log';
    }

    public const NAME = 'mfa';

    /**
     * `/mfa-home`: the signed-in identifier and the token class.
     */
    public function home(): Response
    {
        $tests  = $this->getContainer()->get('test.service_container');
        $tokens = $tests instanceof ContainerInterface ? $tests->get('security.token_storage') : null;
        $token  = $tokens instanceof TokenStorageInterface ? $tokens->getToken() : null;

        return new Response(($token?->getUserIdentifier() ?? 'anonymous') . '|' . ($token instanceof TokenInterface ? $token::class : '-'));
    }

    /**
     * `/mfa-card`: the account-page partial and the grace reminder of the signed-in user.
     */
    public function card(): Response
    {
        $tests = $this->getContainer()->get('test.service_container');
        $twig  = $tests instanceof ContainerInterface ? $tests->get('twig') : null;
        if (!$twig instanceof Environment) {
            return new Response('', 500);
        }

        return new Response($twig->createTemplate("{{ include('@NowoAuthKitBundle/mfa/_account.html.twig') }}{% if auth_kit_mfa_notice() %}<p data-testid=\"notice\">notice</p>{% endif %}")->render());
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'remember_me' => ['enabled' => true],
            'mfa'         => static::MFA + [
                'enabled'            => true,
                'issuer'             => 'Acme',
                'require_encryption' => false,
                'required_roles'     => ['ROLE_ADMIN'],
                'admin'              => ['routes' => true, 'access_roles' => ['ROLE_SUPPORT']],
            ],
        ]);
        $container->loadFromExtension('security', [
            'role_hierarchy' => ['ROLE_SUPPORT' => ['ROLE_ALLOWED_TO_SWITCH']],
            'firewalls'      => ['main' => [
                'remember_me' => ['secret' => 'auth-kit-test', 'remember_me_parameter' => 'login_form[_remember_me]'],
                'switch_user' => true,
            ]],
            'access_control' => [
                ['path' => '^/(en/)?(login|logout)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/mfa-home', 'roles' => 'ROLE_USER'],
            ],
        ]);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register(MockClock::class)->setArguments(['2026-11-03 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, MockClock::class)->setPublic(true);
        $container->setAlias('clock', MockClock::class)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('mfa_home', '/mfa-home')->controller('kernel::home');
        $routes->add('mfa_card', '/mfa-card')->controller('kernel::card');
    }
}
