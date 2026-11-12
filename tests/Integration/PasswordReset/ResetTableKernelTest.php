<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\CapturingPasswordResetNotifier;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * `password_reset.storage: table` through the real kernel (1.46.0, REQ-ARCH-001 rules 2 and 6): the reset request
 * writes only the bundle's `app_auth_kit_password_reset` row, never the user, and never flushes the application's
 * pending changes; the link and code pages complete the reset; a password change in a flush and a host
 * CredentialsChangedEvent clear the row. The worker case keeps one kernel (no reboot, no services resetter) for 600
 * alternating requests of two users.
 */
final class ResetTableKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return ResetTableTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->persist((new KernelUser())->setEmail('bob@example.com')->setPassword('old-password-123'));
        $em->flush();
        $em->clear();
    }

    public function testTheTableStoreIsWired(): void
    {
        self::assertInstanceOf(ProfilePasswordResetTokenManager::class, self::getContainer()->get(PasswordResetTokenManagerInterface::class));
    }

    public function testTheResetRequestWritesOnlyTheTableRowAndLeavesPendingChangesAlone(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form    = $crawler->filter('[data-nowo-ui-region="form"]')->form(['reset_password_request_form[identifier]' => 'ada@example.com']);

        // An application change left pending in the same entity manager: a reset request must not flush it.
        $this->entityManager()->persist((new KernelUser())->setEmail('pending@example.com')->setPassword('x'));
        $this->client->submit($form);
        self::assertResponseRedirects();

        self::assertSame(0, $this->sql("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"));
        $this->entityManager()->clear();
        $ada = $this->entityManager()->find(KernelUser::class, 'ada@example.com');
        self::assertNotNull($ada);
        self::assertNull($ada->getPasswordResetToken(), 'The user is never written by a reset request.');
        self::assertNull($ada->getPasswordResetExpiresAt());
        self::assertSame(1, $this->sql("SELECT COUNT(*) FROM app_auth_kit_password_reset WHERE user_class = 'Nowo\\AuthKitBundle\\Tests\\Integration\\Kernel\\Entity\\KernelUser' AND user_id IS NULL AND user_identifier = 'ada@example.com'"));
        $this->entityManager()->clear();

        $token = $this->notifier()->last?->linkToken();
        self::assertNotNull($token);
        $crawler = $this->client->request('GET', '/reset-password/reset/' . $token);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password"] form')->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseRedirects();
        self::assertSame('new-password-456', $this->sql("SELECT password FROM kernel_users WHERE email = 'ada@example.com'", false));
        self::assertSame(0, $this->sql('SELECT COUNT(*) FROM app_auth_kit_password_reset'), 'Single use: the completion deletes the row.');

        $this->client->request('GET', '/reset-password/reset/' . $token);
        self::assertResponseRedirects();
    }

    public function testTheCodePageCompletesTheReset(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form(['reset_password_request_form[identifier]' => 'bob@example.com']));
        $code = $this->notifier()->last?->code();
        self::assertNotNull($code);

        $crawler = $this->client->request('GET', '/reset-password/complete');
        $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password_code"] form')->form([
            'reset_password_code_form[identifier]'       => 'bob@example.com',
            'reset_password_code_form[code]'             => $code,
            'reset_password_code_form[password][first]'  => 'new-password-456',
            'reset_password_code_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseRedirects();
        self::assertSame('new-password-456', $this->sql("SELECT password FROM kernel_users WHERE email = 'bob@example.com'", false));
        self::assertSame(0, $this->sql('SELECT COUNT(*) FROM app_auth_kit_password_reset'));
    }

    public function testAPasswordChangeAndCredentialsChangedEventClearTheRow(): void
    {
        $manager = self::getContainer()->get(PasswordResetTokenManagerInterface::class);
        self::assertInstanceOf(PasswordResetTokenManagerInterface::class, $manager);
        $ada = $this->entityManager()->find(KernelUser::class, 'ada@example.com');
        $bob = $this->entityManager()->find(KernelUser::class, 'bob@example.com');
        self::assertNotNull($ada);
        self::assertNotNull($bob);

        $manager->createForUser($ada);
        $manager->createForUser($bob);
        $ada->setPassword('changed-elsewhere');
        $this->entityManager()->flush();
        self::assertSame(0, $this->sql("SELECT COUNT(*) FROM app_auth_kit_password_reset WHERE user_identifier = 'ada@example.com'"));
        self::assertSame(1, $this->sql("SELECT COUNT(*) FROM app_auth_kit_password_reset WHERE user_identifier = 'bob@example.com'"));

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new CredentialsChangedEvent($bob));
        self::assertSame(0, $this->sql('SELECT COUNT(*) FROM app_auth_kit_password_reset'));
    }

    /**
     * REQ-RUNTIME-002: one kernel kept alive (no reboot; no HTTP request in the loop, so the services resetter never
     * runs) serves 600 alternating reset requests and resolutions of two users through the container's services. Each link and code resolves only its own user, the other user's earlier
     * link keeps resolving to that user, and memory stays flat (2 MiB margin after a 100-iteration warm-up).
     */
    public function testWorkerModeAlternatingUsersNeverCrossAndMemoryStaysFlat(): void
    {
        // kernel.debug off: the Doctrine debug middleware of the test kernel would otherwise keep every query.
        self::ensureKernelShutdown();
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $manager   = $container->get(PasswordResetTokenManagerInterface::class);
        $lookup    = $container->get(UserLookupInterface::class);
        self::assertInstanceOf(PasswordResetTokenManagerInterface::class, $manager);
        self::assertInstanceOf(UserLookupInterface::class, $lookup);
        $profiles = $container->get(ProfileRegistry::class);
        self::assertInstanceOf(ProfileRegistry::class, $profiles);
        $profile = $profiles->getDefault();

        $last     = [];
        $lastCode = [];
        $baseline = 0;
        for ($i = 0; $i < 600; ++$i) {
            $email = $i % 2 === 0 ? 'ada@example.com' : 'bob@example.com';
            $other = $i % 2 === 0 ? 'bob@example.com' : 'ada@example.com';
            $user  = $lookup->findByIdentifier($profile, $email);
            self::assertNotNull($user);

            $result = $manager->createForUser($user);
            $link   = $result->linkToken();
            $code   = $result->code();
            self::assertNotNull($link);
            self::assertNotNull($code);
            self::assertSame($email, $this->identifier($manager->resolveUserByLinkToken($link)));
            if (($lastCode[$other] ?? null) !== $code) {
                self::assertNull($manager->resolveUserByIdentifierAndCode($other, $code), 'A code never resolves another user.');
            }
            self::assertSame($email, $this->identifier($manager->resolveUserByIdentifierAndCode($email, $code)));
            if (isset($last[$other])) {
                self::assertSame($other, $this->identifier($manager->resolveUserByLinkToken($last[$other])));
            }
            $last[$email]     = $link;
            $lastCode[$email] = $code;

            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline);
        self::assertSame(2, $this->sql('SELECT COUNT(*) FROM app_auth_kit_password_reset'), 'One pending row per user.');
    }

    private function identifier(?object $user): ?string
    {
        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    private function sql(string $sql, bool $int = true): int|string
    {
        $value = $this->entityManager()->getConnection()->fetchOne($sql);

        return $int ? (int) $value : (string) $value;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function notifier(): CapturingPasswordResetNotifier
    {
        $notifier = self::getContainer()->get(CapturingPasswordResetNotifier::class);
        self::assertInstanceOf(CapturingPasswordResetNotifier::class, $notifier);

        return $notifier;
    }
}
