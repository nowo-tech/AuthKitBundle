<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * REQ-ARCH-001 rule 6 (1.46.0, `doctrine.private_entity_manager: true`): the AuthKit writes (QR login challenge,
 * invitation, MFA credential, social account) go through the bundle's private entity manager and never flush a change
 * the application left pending in its own manager. {@see LegacyEntityManagerKernelTest} documents the 1.45 default.
 */
final class PrivateEntityManagerKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return PrivateEmMfaTestKernel::class;
    }

    public function testTheBundleServicesUseThePrivateManager(): void
    {
        $container = self::getContainer();
        self::assertTrue($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $private = $container->get(AuthKitEntityManager::class);
        self::assertInstanceOf(AuthKitEntityManager::class, $private);

        foreach ([QrLoginChallengeRepository::class, InvitationRepository::class] as $repository) {
            $service = $container->get($repository);
            self::assertTrue($service instanceof QrLoginChallengeRepository || $service instanceof InvitationRepository);
            self::assertSame($private->live(), $this->entityManagerOf($service), $repository);
        }
        $store = $container->get(MfaStore::class);
        self::assertInstanceOf(MfaStore::class, $store);
        self::assertSame($private, $this->registryOf($store)->getManagerForClass(MfaCredential::class));

        // The social service repository (what getRepository() returns to the host) stays on the application's manager.
        self::assertSame($this->em(), $this->em()->getRepository(SocialLoginAccount::class)->createQueryBuilder('a')->getEntityManager());
        $copy = $container->get(NowoAuthKitExtension::PRIVATE_REPOSITORY_PREFIX . SocialLoginAccountRepository::class);
        self::assertInstanceOf(SocialLoginAccountRepository::class, $copy);
        self::assertSame($private->live(), $copy->createQueryBuilder('a')->getEntityManager());
    }

    public function testBundleWritesLeaveTheApplicationsPendingChangesUntouched(): void
    {
        $this->em()->persist((new KernelUser())->setEmail('pending@example.com')->setPassword('pending-password'));

        // QR login challenge.
        $qr = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        $challenge = $qr->create(Request::create('/login/qr'))['challenge'];
        self::assertSame(1, $this->rows(QrLoginChallenge::class));
        $this->assertStillPending();

        // Invitation.
        $repository = self::getContainer()->get(InvitationRepository::class);
        self::assertInstanceOf(InvitationRepository::class, $repository);
        $repository->save(new Invitation('default', 'invitee@example.com', null, 7, new DateTimeImmutable()));
        self::assertSame(1, $this->rows(Invitation::class));
        $this->assertStillPending();

        // MFA credential (enable() saves through the store).
        $member = $this->manager();
        $secret = $member->newSecret();
        self::assertNotNull($member->enable($this->userWithoutClearing('member@example.com'), $secret, $this->code($secret)));
        self::assertSame(1, $this->rows(MfaCredential::class));
        $this->assertStillPending();

        // Social account (an existing link: tokens refreshed and flushed, outside any transaction).
        $linker = self::getContainer()->get(SocialAccountLinker::class);
        self::assertInstanceOf(SocialAccountLinker::class, $linker);
        $profile = self::getContainer()->get(ProfileRegistry::class);
        self::assertInstanceOf(ProfileRegistry::class, $profile);
        $this->em()->getConnection()->insert($this->table(SocialLoginAccount::class), [
            'provider'        => 'google', 'provider_user_id' => 'sub-1', 'user_class' => KernelUser::class, 'user_id' => 'member@example.com',
            'user_identifier' => 'member@example.com', 'raw_profile' => '[]', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
        ]);
        $user = $linker->linkOrCreate($profile->getDefault(), 'google', new SocialUserProfile('sub-1', 'member@example.com', 'Member', []), ['access_token' => 'at-new', 'refresh_token' => null, 'expires_in' => null]);
        self::assertSame('member@example.com', $user->getUserIdentifier());
        self::assertSame('at-new', $this->em()->getConnection()->fetchOne('SELECT access_token FROM ' . $this->table(SocialLoginAccount::class)));
        $this->assertStillPending();

        // The application decides when its change is stored.
        $this->em()->flush();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"));
        self::assertNotNull($qr->find($challenge->getId()));
    }

    public function testEveryMainRequestStartsWithAFreshPrivateManager(): void
    {
        $private = self::getContainer()->get(AuthKitEntityManager::class);
        self::assertInstanceOf(AuthKitEntityManager::class, $private);
        $qr = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $qr);
        $qr->create(Request::create('/login/qr'));
        self::assertTrue($private->isCreated());
        $live = $private->live();

        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $request  = Request::create('/login');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        self::assertFalse($private->isCreated(), 'Dropped at kernel.request / kernel.terminate.');
        self::assertNotSame($live, $private->live());
    }

    private function assertStillPending(): void
    {
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'pending@example.com'"), 'The AuthKit write did not flush the application\'s pending insert.');
        self::assertCount(1, $this->em()->getUnitOfWork()->getScheduledEntityInsertions(), 'The insert is still scheduled in the application\'s manager.');
    }

    private function userWithoutClearing(string $email): KernelUser
    {
        $user = $this->em()->find(KernelUser::class, $email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($class));
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->em()->getClassMetadata($class)->getTableName();
    }

    private function entityManagerOf(object $repository): EntityManagerInterface
    {
        self::assertTrue($repository instanceof QrLoginChallengeRepository || $repository instanceof InvitationRepository);

        return $repository->createQueryBuilder('r')->getEntityManager();
    }

    private function registryOf(object $store): ManagerRegistry
    {
        $registry = (new ReflectionProperty($store, 'doctrine'))->getValue($store);
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }
}
