<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MfaLockRequiredPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\SamlSharedClaimPoolPass;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 2.10.0: the single-use claim of SAML requests needs a shared pool (never process memory outside `test`) and a lock.
 */
final class SamlSharedClaimPoolPassTest extends TestCase
{
    public function testAnInMemoryPoolFailsTheBuildOutsideTheTestEnvironment(): void
    {
        foreach (['memory', 'array_class', 'alias'] as $pool) {
            try {
                (new SamlSharedClaimPoolPass())->process($this->container($pool, 'prod'));
                self::fail($pool);
            } catch (LogicException $exception) {
                self::assertStringContainsString('profiles.default.saml needs a shared attempt_limiter.cache_pool', $exception->getMessage());
            }
        }
    }

    public function testASharedPoolTheTestEnvironmentOrSamlOffPass(): void
    {
        (new SamlSharedClaimPoolPass())->process($this->container('files', 'prod'));
        (new SamlSharedClaimPoolPass())->process($this->container('plain', 'prod'));
        (new SamlSharedClaimPoolPass())->process($this->container('memory', 'test'));
        (new SamlSharedClaimPoolPass())->process($this->container('memory', 'prod', false));
        (new SamlSharedClaimPoolPass())->process($this->container('unknown', 'prod'));
        (new SamlSharedClaimPoolPass())->process(new ContainerBuilder());
        $this->addToAssertionCount(1);
    }

    public function testALockFactoryIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.saml requires symfony/lock');
        (new MfaLockRequiredPass())->process($this->container('files', 'prod'));
    }

    private function container(string $pool, string $environment, bool $saml = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);
        $container->setParameter('nowo_auth_kit.profiles', ['default' => ['saml' => ['mode' => $saml ? 'enabled' : 'disabled']]]);
        $container->setDefinition('cache.adapter.array', new Definition(ArrayAdapter::class));
        $container->setDefinition('cache.adapter.filesystem', new Definition(FilesystemAdapter::class));
        $container->setDefinition('pool.memory', new ChildDefinition('cache.adapter.array'));
        $container->setDefinition('pool.files', new ChildDefinition('cache.adapter.filesystem'));
        $container->setDefinition('pool.array_class', new Definition(ArrayAdapter::class));
        $container->setDefinition('pool.plain', new Definition(FilesystemAdapter::class));
        $container->setAlias('pool.alias', 'pool.memory');
        $container->setDefinition(AuthKitAttemptLimiter::class, (new Definition(AuthKitAttemptLimiter::class))->setArgument('$cache', new Reference('pool.' . $pool)));

        return $container;
    }
}
