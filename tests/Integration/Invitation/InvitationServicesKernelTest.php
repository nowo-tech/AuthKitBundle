<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\Controller\InvitationAdminController;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\InvitationFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationMailerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Invitation\Ui\InvitationAdminPages;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

/**
 * Edge branches of the invitation services and the fail-closed paths of the controllers (forms without the CSRF
 * extension, disabled profiles, non-path redirect targets), on the services of the invitations kernel.
 */
final class InvitationServicesKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return InvitationsTestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testInviteRefusesInvalidEmailsAndForgedRoles(): void
    {
        try {
            $this->manager()->invite(null, 'default', new InvitationRequest('not-an-email'));
            self::fail('An invalid e-mail must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid invitation e-mail.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Roles not grantable by invitation in profile "default": ROLE_ROOT.');
        $this->manager()->invite(null, 'default', new InvitationRequest('a@example.com', ['ROLE_EDITOR', 'ROLE_ROOT']));
    }

    public function testReinvitingMergesIntoTheOpenInvitationAndResendsAreLimited(): void
    {
        $manager = $this->manager();
        $first   = $manager->invite(null, 'default', new InvitationRequest('a@example.com', ['ROLE_EDITOR'], 3, 'Hi', 'es', ['team' => 'blue']));
        $second  = $manager->invite(null, 'default', new InvitationRequest('A@example.com', ['ROLE_SUPER_ADMIN'], 999, null, 'xx', ['seat' => 1]));

        self::assertSame(InvitationOutcome::SENT, $second->status);
        self::assertNotNull($second->invitation);
        self::assertSame($first->invitation?->publicId, $second->invitation->publicId);
        self::assertSame(['ROLE_EDITOR', 'ROLE_SUPER_ADMIN'], $second->invitation->roles);
        self::assertSame(['team' => 'blue', 'seat' => 1], $second->invitation->payload);
        self::assertSame('Hi', $second->invitation->message);
        self::assertNull($second->invitation->locale, 'A locale that is not enabled is dropped.');
        self::assertSame(7, $second->invitation->ttlDays, 'A validity that is not offered falls back to ttl_days.');
        self::assertInstanceOf(InvitationResentEvent::class, $this->listener()->events[1] ?? null);

        $invitation = $this->repository()->findOneByPublicId('default', (string) $second->invitation->publicId);
        self::assertInstanceOf(Invitation::class, $invitation);
        // resend_rate_limit: 3 sends per invitation and window (the re-invite counted as one).
        $statuses = [];
        for ($i = 0; $i < 3; ++$i) {
            $statuses[] = $manager->resend(null, $invitation)->status;
        }
        $statuses[] = $manager->invite(null, 'default', new InvitationRequest('a@example.com'))->status;
        self::assertSame([InvitationOutcome::SENT, InvitationOutcome::SENT, InvitationOutcome::RATE_LIMITED, InvitationOutcome::RATE_LIMITED], $statuses);
    }

    public function testDisabledProfilesRefuseTheManager(): void
    {
        $manager = new InvitationManager(
            $this->repository(),
            ProfileRegistryFactory::single(TestUser::class),
            $this->service(InvitationMailerInterface::class),
            $this->service(AuthKitAttemptLimiter::class),
            $this->service(AuthKitUrlGenerator::class),
            $this->service(ClockInterface::class),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invitations are disabled in the AuthKit profile "default"');
        $manager->invite(null, 'default', new InvitationRequest('a@example.com'));
    }

    public function testAcceptorRefusesStaleOtherProfileAndClaimedInvitations(): void
    {
        $outcome    = $this->manager()->invite(null, 'default', new InvitationRequest('b@example.com'));
        $invitation = $this->repository()->findOneByPublicId('default', (string) $outcome->invitation?->publicId);
        self::assertInstanceOf(Invitation::class, $invitation);
        $hash     = $invitation->getTokenHash();
        $acceptor = $this->service(InvitationAcceptor::class);

        self::assertSame([null, null], $acceptor->lookup('other', $hash), 'A token of another profile is unknown.');
        self::assertSame([null, null], $acceptor->lookup('default', ''));
        self::assertNull($acceptor->accept($invitation, 'not-the-hash', ['email' => 'b@example.com', 'password' => 'correct-horse-battery']));

        // Accepted elsewhere meanwhile: this (stale) copy cannot claim it.
        $this->entityManager()->createQuery('UPDATE ' . Invitation::class . ' i SET i.acceptedAt = :now')->setParameter('now', new DateTimeImmutable())->execute();
        self::assertNull($acceptor->accept($invitation, $hash, ['email' => 'b@example.com', 'password' => 'correct-horse-battery']));
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('b@example.com'));

        $invitation->markAccepted('b@example.com', new DateTimeImmutable());
        self::assertNull($acceptor->accept($invitation, $hash, []), 'No longer pending.');
    }

    public function testAcceptorRethrowsUnexpectedGrantErrorsAfterRollingBack(): void
    {
        $outcome    = $this->manager()->invite(null, 'default', new InvitationRequest('c@example.com'));
        $invitation = $this->repository()->findOneByPublicId('default', (string) $outcome->invitation?->publicId);
        self::assertInstanceOf(Invitation::class, $invitation);
        $this->listener()->crash = true;

        try {
            $this->service(InvitationAcceptor::class)->accept($invitation, $invitation->getTokenHash(), ['email' => 'c@example.com', 'password' => 'correct-horse-battery']);
            self::fail('A grant bug must surface.');
        } catch (LogicException $exception) {
            self::assertSame('Grant bug.', $exception->getMessage());
        } finally {
            $this->listener()->crash = false;
        }
        self::assertNull($this->entityManager()->getRepository(KernelUser::class)->find('c@example.com'));
        $fresh = $this->repository()->findOneByPublicId('default', $invitation->getPublicId());
        self::assertNull($fresh?->getAcceptedAt());
    }

    public function testAdminControllerFailsClosed(): void
    {
        $this->signIn(['ROLE_ADMIN']);
        $request = $this->request('POST', '/admin/invitations/new');

        try {
            $this->adminController($this->formFactoryWithoutCsrf())->new($request);
            self::fail('The invite form must not be processed without CSRF protection.');
        } catch (AccessDeniedHttpException $exception) {
            self::assertSame('CSRF protection is not available.', $exception->getMessage());
        }

        $this->expectException(NotFoundHttpException::class);
        $this->adminController($this->service(FormFactoryInterface::class), ProfileRegistryFactory::single(TestUser::class))->index($this->request('GET', '/admin/invitations'));
    }

    public function testAcceptControllerFailsClosed(): void
    {
        $outcome    = $this->manager()->invite(null, 'default', new InvitationRequest('d@example.com'));
        $invitation = $this->repository()->findOneByPublicId('default', (string) $outcome->invitation?->publicId);
        self::assertInstanceOf(Invitation::class, $invitation);
        $request = $this->request('POST', '/invitation');
        $request->getSession()->set(InvitationAcceptController::SESSION_KEY . 'default', $invitation->getTokenHash());

        try {
            $this->acceptController($this->formFactoryWithoutCsrf())->accept($request);
            self::fail('No account may be created without CSRF protection.');
        } catch (AccessDeniedHttpException $exception) {
            self::assertSame('CSRF protection is not available.', $exception->getMessage());
        }

        $this->expectException(NotFoundHttpException::class);
        $this->acceptController($this->service(FormFactoryInterface::class), ProfileRegistryFactory::single(TestUser::class))->accept($this->request('GET', '/invitation'));
    }

    public function testAcceptCsrfTokenIdBindsTheInvitation(): void
    {
        self::assertSame('nowo_auth_kit_invitation_accept/default/abc', InvitationAcceptController::csrfTokenId('default', 'abc'));
        self::assertSame('nowo_auth_kit_invitation_new/default', InvitationFormType::csrfTokenId('default'));

        $forms = $this->service(FormFactoryInterface::class);
        self::assertSame('bound/default/abc', $forms->create(RegistrationFormType::class, null, ['invitation_csrf_token_id' => 'bound/default/abc'])->getConfig()->getOption('csrf_token_id'));
        self::assertNull($forms->create(RegistrationFormType::class)->getConfig()->getOption('csrf_token_id'), 'The open registration keeps its token id.');
    }

    public function testRedirectTargetsArePathsOnly(): void
    {
        self::assertSame('/admin/invitations', InvitationAdminController::pathOnly('/admin/invitations'));
        foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', 'admin'] as $target) {
            try {
                InvitationAdminController::pathOnly($target);
                self::fail($target . ' must be refused.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function adminController(FormFactoryInterface $forms, ?ProfileRegistry $registry = null): InvitationAdminController
    {
        return new InvitationAdminController(
            $this->service(Environment::class),
            $forms,
            new RequestProfileResolver($registry ?? $this->service(ProfileRegistry::class)),
            $this->service(InvitationsAccessGuard::class),
            $this->manager(),
            $this->repository(),
            $this->service(InvitationAdminPages::class),
            $this->service(CsrfOnlyFormFactory::class),
            $this->service(AuthKitUrlGenerator::class),
            $this->service(AuthKitFlashMessenger::class),
            $this->service(ClockInterface::class),
        );
    }

    private function acceptController(FormFactoryInterface $forms, ?ProfileRegistry $registry = null): InvitationAcceptController
    {
        $registry ??= $this->service(ProfileRegistry::class);

        return new InvitationAcceptController(
            $this->service(Environment::class),
            $forms,
            new RequestProfileResolver($registry),
            new RegistrationGate($this->entityManager(), $registry),
            $this->service(InvitationAcceptor::class),
            $this->service(AuthKitAttemptLimiter::class),
            $this->service(TokenStorageInterface::class),
            $this->service(AuthKitUrlGenerator::class),
            $this->service(Security::class),
        );
    }

    private function formFactoryWithoutCsrf(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType($this->service(InvitationFormType::class))
            ->addType($this->service(RegistrationFormType::class))
            ->getFormFactory();
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): void
    {
        $user = (new KernelUser())->setEmail('admin@example.com')->setRoles($roles);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function request(string $method, string $path): Request
    {
        $request = Request::create($path, $method);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');

        return $request;
    }

    private function manager(): InvitationManager
    {
        return $this->service(InvitationManager::class);
    }

    private function repository(): InvitationRepository
    {
        return $this->service(InvitationRepository::class);
    }

    private function listener(): CapturingInvitationListener
    {
        return $this->service(CapturingInvitationListener::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        /** @var T $service */
        $service = self::getContainer()->get($id);

        return $service;
    }
}
