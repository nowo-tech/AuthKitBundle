<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

/**
 * Audit LD-AK-M1, kept-alive kernel (FRANKENPHP_RESET_KERNEL=false, no kernel.reset): with
 * `doctrine.entity_manager` naming a second manager, a failed QR write still reopens the manager the AuthKit entities
 * are written through, so later requests keep working; the host's closed "reporting" manager stays closed.
 */
final class DddEntityManagerKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DddEntityManagerTestKernel::class;
    }

    public function testManagerOfTheEntitiesRecoversWithTheOptionSet(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $default   = $this->manager($doctrine, 'default');
        $reporting = $this->manager($doctrine, 'reporting');
        $schema    = new SchemaTool($default);
        $metadata  = $default->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $manager = $container->get('test.ddd.qr_login_manager');
        self::assertInstanceOf(QrLoginChallengeManager::class, $manager);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        $reporting->close();
        $default->getConnection()->executeStatement('DROP TABLE ' . $default->getClassMetadata(QrLoginChallenge::class)->getTableName());
        try {
            $manager->create(Request::create('/login/qr'));
            self::fail('The write must fail without its table.');
        } catch (Throwable) {
        }
        self::assertTrue($default->isOpen(), 'The manager the AuthKit entities are written through is reopened.');

        $schema->createSchema([$default->getClassMetadata(QrLoginChallenge::class)]);
        for ($i = 0; $i < 20; ++$i) {
            $this->serve($http);
            $challenge = $manager->create(Request::create('/login/qr'))['challenge'];
            self::assertNotNull($manager->find($challenge->getId()));
        }
        self::assertFalse($reporting->isOpen(), 'The bundle must never reset the host manager.');
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function serve(HttpKernelInterface $http): void
    {
        $request  = Request::create('/login');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
