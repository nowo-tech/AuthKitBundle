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
 * FrankenPHP worker mode with the kernel kept alive and never reset (FRANKENPHP_RESET_KERNEL=false, no services
 * resetter): one booted kernel serves every request through the http_kernel service, so kernel.reset never runs.
 * A failed AuthKit write closes the bundle's entity manager; the bundle reopens it and the next requests write again,
 * while the host's own closed manager ("reporting") is never touched by the bundle.
 */
final class WorkerEntityManagerRecoveryKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkerRecoveryTestKernel::class;
    }

    public function testBundleManagerRecoversAcrossRequestsAndHostManagerIsUntouched(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $bundle = $doctrine->getManager('default');
        $host   = $doctrine->getManager('reporting');
        self::assertInstanceOf(EntityManagerInterface::class, $bundle);
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $schema   = new SchemaTool($bundle);
        $metadata = $bundle->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $manager = $container->get('test.worker.qr_login_manager');
        self::assertInstanceOf(QrLoginChallengeManager::class, $manager);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        // The host closes its own manager (e.g. a failed host flush) and keeps it closed on purpose.
        $host->close();
        $this->serve($http);

        // A bundle write fails: the QR table is gone, the flush throws and closes the bundle manager.
        $table = $bundle->getClassMetadata(QrLoginChallenge::class)->getTableName();
        $bundle->getConnection()->executeStatement('DROP TABLE ' . $table);
        try {
            $manager->create(Request::create('/login/qr'));
            self::fail('The write must fail without its table.');
        } catch (Throwable) {
        }
        self::assertTrue($bundle->isOpen(), 'The bundle reopens its own manager after the failed write.');
        self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');

        // Later requests served by the same kernel write again through the same (reset in place) manager.
        $schema->createSchema([$bundle->getClassMetadata(QrLoginChallenge::class)]);
        for ($i = 0; $i < 20; ++$i) {
            $this->serve($http);
            $challenge = $manager->create(Request::create('/login/qr'))['challenge'];
            self::assertNotNull($manager->find($challenge->getId()));
        }
        self::assertTrue($bundle->isOpen());
        self::assertFalse($host->isOpen(), 'Still closed: only the host decides when to reset it.');
        self::assertSame($host, $doctrine->getManager('reporting'));
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
