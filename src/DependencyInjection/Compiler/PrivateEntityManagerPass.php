<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Private entity manager wiring (1.46.0, REQ-ARCH-001 rule 6, `doctrine.private_entity_manager: true`):
 *
 * - the source manager is DoctrineBundle's default manager: the manager 1.45 wrote the AuthKit rows through (its
 *   repositories were autowired with it), so the tables do not move and the bundle's own transactions (invitation
 *   acceptance, social sign-in) keep one connection for the AuthKit rows and the application's user.
 *   `doctrine.entity_manager` stays what it was (the closed-manager recovery set); it is set on
 *   {@see AuthKitEntityManager} and {@see AuthKitManagerRegistry};
 * - attaches {@see PrivateEntityManagerMiddleware} only to the connection of that manager (the first argument of its
 *   definition; the default connection when it cannot be read): the host's other connections are never wrapped, and the
 *   middleware and the private manager always see the same connection.
 *
 * Runs before DoctrineBundle's MiddlewaresPass. Nothing happens without the option (the services are not registered).
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (no replacement after a rollback, see docs/CONFIGURATION.md).
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    public function process(ContainerBuilder $container): void
    {
        // The excluded classes are also registered (abstract, `container.excluded`): the option decides.
        if (!$container->hasParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) || $container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) !== true) {
            return;
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $manager = is_string($default) && $default !== '' ? $default : 'default';
        $container->getDefinition(AuthKitEntityManager::class)->setArgument('$sourceManagerName', $manager);
        $container->getDefinition(AuthKitManagerRegistry::class)->setArgument('$managerName', $manager);

        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class) || $container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('container.excluded')) {
            return;
        }

        $id         = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
