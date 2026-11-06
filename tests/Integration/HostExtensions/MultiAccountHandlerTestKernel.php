<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\MultiAccountTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The account switcher kernel (UserKit sessions, MFA, remember-me) with social login and a callback handler.
 */
final class MultiAccountHandlerTestKernel extends MultiAccountTestKernel
{
    public const NAME = 'multi-account-handler';

    public function pending(Request $request): Response
    {
        $request->getSession()->set(PendingStepCallbackHandler::SESSION_KEY, true);

        return new Response('pending');
    }

    /** `/test/session`: the session attributes, for the tests. */
    public function sessionAttributes(Request $request): JsonResponse
    {
        return new JsonResponse($request->getSession()->all());
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', ['http_client' => ['mock_response_factory' => SocialProviderResponses::class]]);
        $container->loadFromExtension('nowo_auth_kit', ['social_login' => ['mode' => 'enabled']]);
        $container->register(SocialProviderResponses::class)->setPublic(true);
        $container->register(ThrowingLoginListener::class)
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'dispatcher' => 'security.event_dispatcher.main', 'priority' => 100]);
        $container->register(PendingStepCallbackHandler::class)->setAutowired(true)->setAutoconfigured(true)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('test_pending', '/test/pending')->controller('kernel::pending');
        $routes->add('test_session', '/test/session')->controller('kernel::sessionAttributes');
    }
}
