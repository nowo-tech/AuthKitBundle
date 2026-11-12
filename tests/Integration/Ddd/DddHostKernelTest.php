<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Ddd;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\Port\QrLoginStepUpGate;
use Nowo\AuthKitBundle\Port\UserDirectoryGate;
use Nowo\AuthKitBundle\QrLogin\DeviceIntelligenceQrLoginStepUp;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\CapturingPasswordResetNotifier;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Support\Ports\InMemoryTwinUsers;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * REQ-ARCH-001 "both host kinds tested", DDD side (1.46.0): {@see DddHostTestKernel} boots with a non-Doctrine security
 * user served only through the framework-free user ports, the bundle's own reset table, XML mapping and the private
 * entity manager. Registration, sign-in and the whole password reset run through the HTTP pages; the host's pending
 * Doctrine change is never flushed by AuthKit, and the bundle's deprecated aliases are served by the 1.46 gates (the
 * Device Intelligence step-up decorator still wraps the host's step-up policy).
 */
final class DddHostKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return DddHostTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $em     = $this->entityManager();
        $schema = new SchemaTool($em);
        $all    = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($all);
        $schema->createSchema($all);
    }

    public function testRegisterSignInAndResetThroughTheFrameworkFreePortsWithoutFlushingTheHostManager(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form([
            'registration_form[email]'           => 'ada@example.com',
            'registration_form[password][first]'  => 'correct-horse-battery',
            'registration_form[password][second]' => 'correct-horse-battery',
        ]));
        self::assertResponseRedirects();
        $this->assertHostChangeStillPending();
        self::assertSame(['add ada@example.com'], $this->users()->calls);
        $ada = $this->users()->loadUserByIdentifier('ada@example.com');
        self::assertInstanceOf(HostDomainUser::class, $ada);

        // Sign in through the firewall's provider (the same adapter).
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form([
            'login_form[_username]' => 'ada@example.com',
            'login_form[_password]' => 'correct-horse-battery',
        ]));
        self::assertResponseRedirects('/home');
        $this->assertHostChangeStillPending();

        // Password reset: credential in the bundle's table, user found again through the directory.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form(['reset_password_request_form[identifier]' => 'ada@example.com']));
        self::assertResponseRedirects();
        $this->assertHostChangeStillPending();
        self::assertSame(1, $this->resetRows());
        $token = $this->notifier()->last?->linkToken();
        self::assertNotNull($token);

        $this->client->request('GET', '/reset-password/reset/' . $token);
        $crawler = $this->client->getResponse()->isRedirection() ? $this->client->followRedirect() : $this->client->getCrawler();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password"] form')->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseRedirects();
        $this->assertHostChangeStillPending();
        self::assertSame('new-password-456', $ada->getPassword());
        self::assertSame(['add ada@example.com', 'changePassword ada@example.com', 'save ada@example.com'], $this->users()->calls);
        self::assertSame(0, $this->resetRows(), 'The reset credential is single use.');

        // AuthKit never flushed the host's unit of work: none of the pending inserts reached the database.
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_users'));
    }

    public function testTheDeprecatedAliasesAreServedByTheGatesAndTheDeviceDecoratorStillWraps(): void
    {
        $container = self::getContainer();
        self::assertInstanceOf(UserDirectoryGate::class, $container->get('test.ddd.user_lookup'));

        $stepUp = $container->get('test.ddd.qr_step_up');
        self::assertInstanceOf(DeviceIntelligenceQrLoginStepUp::class, $stepUp);
        self::assertInstanceOf(QrLoginStepUpGate::class, (new ReflectionProperty(DeviceIntelligenceQrLoginStepUp::class, 'inner'))->getValue($stepUp));
    }

    /**
     * The change {@see PendingHostChangeListener} left pending at the start of the request is still scheduled after
     * AuthKit's writes of that request (the test kernel's services resetter clears the manager before the next one).
     */
    private function assertHostChangeStillPending(): void
    {
        $listener = self::getContainer()->get(PendingHostChangeListener::class);
        self::assertInstanceOf(PendingHostChangeListener::class, $listener);
        self::assertNotNull($listener->last);
        self::assertTrue($this->entityManager()->getUnitOfWork()->isScheduledForInsert($listener->last));
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function users(): InMemoryTwinUsers
    {
        $users = self::getContainer()->get(InMemoryTwinUsers::class);
        self::assertInstanceOf(InMemoryTwinUsers::class, $users);

        return $users;
    }

    private function notifier(): CapturingPasswordResetNotifier
    {
        $notifier = self::getContainer()->get(CapturingPasswordResetNotifier::class);
        self::assertInstanceOf(CapturingPasswordResetNotifier::class, $notifier);

        return $notifier;
    }

    private function resetRows(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . PasswordResetRequest::TABLE);
    }
}
