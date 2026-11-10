<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Proxy;

use function array_combine;
use function array_keys;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * A real ManagerRegistry over in-memory SQLite entity managers: each manager maps only the namespaces given to it,
 * and resetManager() replaces the instance (as DoctrineBundle does for non-lazy managers).
 */
final class SqliteManagerRegistry extends AbstractManagerRegistry
{
    /** @var array<string, EntityManagerInterface> */
    private array $services = [];

    /**
     * @param array<string, array<string, string>> $mappings manager name => [namespace => directory]
     */
    public function __construct(private readonly array $mappings, string $defaultManager = 'default')
    {
        $names = array_keys($mappings);

        parent::__construct('ORM', [], array_combine($names, $names), '', $defaultManager, Proxy::class);
    }

    protected function getService(string $name): object
    {
        return $this->services[$name] ??= $this->create($name);
    }

    protected function resetService(string $name): void
    {
        $this->services[$name] = $this->create($name);
    }

    private function create(string $name): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-registry-tests/proxies');
            $config->setProxyNamespace('NowoRegistryTestProxies');
        }
        $chain = new MappingDriverChain();
        foreach ($this->mappings[$name] as $namespace => $directory) {
            $chain->addDriver(new AttributeDriver([$directory]), $namespace);
        }
        $config->setMetadataDriverImpl($chain);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
