<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Multi-account (1.34) firewall data, fail closed: UserKit ≥ 1.11 sessions are required (D1); every multi-account
 * firewall must be stateful; the vault gets each firewall's user checker, security context (`_security_<context>`)
 * and remember-me cookie (name, path, domain, secure, SameSite, lifetime).
 */
final class MultiAccountPass implements CompilerPassInterface
{
    public const FIREWALLS_PARAMETER = 'nowo_auth_kit.multi_account.firewalls';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AccountVault::class) || !$container->hasParameter(self::FIREWALLS_PARAMETER)) {
            return;
        }
        if (!$container->has(UserSessionManager::class)) {
            throw new LogicException('NowoAuthKitBundle: multi_account requires nowo-tech/user-kit-bundle >= 1.11 with nowo_user_kit.sessions.enabled: true (separate, revocable sessions per account).');
        }
        /** @var list<string> $firewalls */
        $firewalls  = $container->getParameter(self::FIREWALLS_PARAMETER);
        $checkers   = [];
        $providers  = [];
        $contexts   = [];
        $rememberMe = [];
        foreach ($firewalls as $firewall) {
            $configId = 'security.firewall.map.config.' . $firewall;
            if (!$container->hasDefinition($configId)) {
                throw new LogicException(sprintf('NowoAuthKitBundle: multi_account needs the firewall "%s" (profile firewall).', $firewall));
            }
            $arguments = $container->getDefinition($configId)->getArguments();
            if (($arguments[4] ?? false) === true) {
                throw new LogicException(sprintf('NowoAuthKitBundle: multi_account needs a stateful firewall; "%s" is stateless.', $firewall));
            }
            $context             = $arguments[6] ?? null;
            $contexts[$firewall] = is_string($context) && $context !== '' ? $context : $firewall;
            $checkers[$firewall] = new Reference('security.user_checker.' . $firewall);
            // The firewall's own user provider (DE-I5): several user classes may share identifiers.
            $provider = $arguments[5] ?? null;
            if (is_string($provider) && $container->has($provider)) {
                $providers[$firewall] = new Reference($provider);
            }
            $rememberMe[$firewall] = null;
        }
        foreach ($container->findTaggedServiceIds('security.remember_me_handler') as $id => $tags) {
            $firewall = $tags[0]['firewall'] ?? null;
            if (!is_string($firewall) || !array_key_exists($firewall, $rememberMe)) {
                continue;
            }
            $arguments = $container->getDefinition($id)->getArguments();
            $options   = $arguments['index_3'] ?? $arguments[3] ?? null;
            if (!is_array($options)) {
                continue;
            }
            $rememberMe[$firewall] = [
                'name'     => is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : 'REMEMBERME',
                'path'     => is_string($options['path'] ?? null) && $options['path'] !== '' ? $options['path'] : '/',
                'domain'   => is_string($options['domain'] ?? null) && $options['domain'] !== '' ? $options['domain'] : null,
                'secure'   => is_bool($options['secure'] ?? null) || is_string($options['secure'] ?? null) ? $options['secure'] : null,
                'samesite' => is_string($options['samesite'] ?? null) ? $options['samesite'] : null,
                'lifetime' => is_int($options['lifetime'] ?? null) ? $options['lifetime'] : 31536000,
            ];
        }
        $vault = $container->getDefinition(AccountVault::class);
        $vault->setArgument('$userCheckers', ServiceLocatorTagPass::register($container, $checkers));
        $vault->setArgument('$rememberMe', $rememberMe);
        $vault->setArgument('$contexts', $contexts);
        $vault->setArgument('$userProviders', ServiceLocatorTagPass::register($container, $providers));
    }
}
