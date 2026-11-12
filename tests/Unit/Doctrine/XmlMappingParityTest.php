<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function dirname;
use function get_object_vars;
use function is_bool;
use function ksort;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * 1.46.0: the shipped XML mapping (src/Resources/config/doctrine-xml/*.orm.xml) describes exactly the tables, columns,
 * indexes and constraints of the entity attributes, with and without the table prefix, so
 * `doctrine.mapping_type: xml` moves no column and drops nothing. The `#[Encrypted]` fields stay encrypted: the
 * encryption reads the property attributes by reflection, whatever the mapping driver.
 */
#[CoversNothing]
final class XmlMappingParityTest extends TestCase
{
    /** @var list<class-string> */
    private const ENTITIES = [
        AccountVaultEntry::class,
        Invitation::class,
        MfaCredential::class,
        MfaRecoveryCode::class,
        MfaTrustedDevice::class,
        QrLoginChallenge::class,
        SocialLoginAccount::class,
        SocialLoginCredential::class,
    ];

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function entities(): iterable
    {
        foreach (self::ENTITIES as $class) {
            foreach (['', 'app_'] as $prefix) {
                yield $class . ' / "' . $prefix . '"' => [$class, $prefix];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entities')]
    public function theXmlMetadataMatchesTheAttributes(string $class, string $prefix): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), $prefix)->getClassMetadata($class);
        $xml       = $this->manager($this->xmlDriver(), $prefix)->getClassMetadata($class);

        self::assertSame($attribute->getTableName(), $xml->getTableName());
        self::assertSame($attribute->getIdentifier(), $xml->getIdentifier());
        self::assertSame($attribute->generatorType, $xml->generatorType);
        self::assertSame($attribute->customRepositoryClassName, $xml->customRepositoryClassName);
        self::assertEquals($this->sorted($attribute->table['indexes'] ?? []), $this->sorted($xml->table['indexes'] ?? []));
        self::assertEquals($this->sorted($attribute->table['uniqueConstraints'] ?? []), $this->sorted($xml->table['uniqueConstraints'] ?? []));
        self::assertEquals($this->fields($attribute), $this->fields($xml));
        self::assertSame([], $xml->associationMappings);
    }

    #[Test]
    public function theXmlSchemaHasNoDifference(): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), 'app_');
        $xml       = $this->manager($this->xmlDriver(), 'app_');
        $from      = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $to        = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());

        self::assertCount(8, $xml->getMetadataFactory()->getAllMetadata());
        self::assertTrue($attribute->getConnection()->createSchemaManager()->createComparator()->compareSchemas($from, $to)->isEmpty());
    }

    #[Test]
    public function theEncryptedFieldsKeepTheirAttribute(): void
    {
        foreach ([[MfaCredential::class, 'totpSecret'], [SocialLoginAccount::class, 'accessToken'], [SocialLoginAccount::class, 'refreshToken'], [SocialLoginCredential::class, 'clientSecret']] as [$class, $property]) {
            self::assertCount(1, (new ReflectionProperty($class, $property))->getAttributes(Encrypted::class), $class . '::$' . $property);
        }
    }

    private function xmlDriver(): MappingDriver
    {
        return new SimplifiedXmlDriver([dirname(__DIR__, 3) . '/src/Resources/config/doctrine-xml' => 'Nowo\\AuthKitBundle\\Entity']);
    }

    private function manager(MappingDriver $driver, string $prefix): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-auth-kit-xml-test-proxies');
        $config->setProxyNamespace('NowoAuthKitXmlTestProxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $events = new EventManager();
        if ($prefix !== '') {
            $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener($prefix));
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $mapping) {
            $vars = get_object_vars($mapping);
            // XML options are strings: a boolean default is written 0 / 1.
            foreach ($vars['options'] ?? [] as $option => $value) {
                $vars['options'][$option] = is_bool($value) ? (string) (int) $value : $value;
            }
            $fields[$name] = $vars;
        }
        ksort($fields);

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $definitions): array
    {
        ksort($definitions);

        return $definitions;
    }
}
