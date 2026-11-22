<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoadReadiness;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * 1.50.0 (Beacon spec 239), one booted kernel serving every request through `http_kernel` and never reset (FrankenPHP
 * worker mode, kernel reset off): a failure in one request leaves the next one healthy, and a failed AuthKit unit keeps
 * the application's entities managed. Shared manager and private manager (`*_private`).
 */
final class LoadReadinessKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return LoadReadinessTestKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        // 2.0: the private manager is the only mode (1.x also ran the shared manager here).
        yield 'private manager' => ['test'];
    }

    #[DataProvider('modes')]
    public function testAFailureInRequestOneLeavesRequestTwoHealthy(string $environment): void
    {
        $this->boot($environment);
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $this->em()->getConnection()->insert('kernel_users', ['email' => 'taken@example.com', 'password' => 'x', 'roles' => '[]', 'newsletter' => 0]);

        // Request 1: an AuthKit unit whose flush hits a taken key (the application's manager writes the account).
        $request  = Request::create('/login');
        $response = $http->handle($request);
        $runner   = self::getContainer()->get(AuthKitTransactionRunner::SERVICE_ID);
        self::assertInstanceOf(AuthKitTransactionRunner::class, $runner);
        try {
            $runner->runWithAccounts(function (): void {
                $this->em()->persist((new KernelUser())->setEmail('taken@example.com')->setPassword('y'));
                $this->em()->flush();
            });
            self::fail('the key is taken');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em()->isOpen(), 'closed for the rest of request 1: the application\'s next flush fails loudly');
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel(), 'no transaction level left open');
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        // Request 2, same kernel, no kernel reset: the boundary replaced the manager; a registration works.
        $request  = Request::create('/login');
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->em()->isOpen());
        $user = $this->service(UserRegistrar::class)->register(['email' => 'fresh@example.com', 'password' => 'secret-123'], 'default');
        self::assertSame('fresh@example.com', $user->getUserIdentifier());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'fresh@example.com'"));
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }

    #[DataProvider('modes')]
    public function testASecondRegistrationOfOneIdentifierIsRefusedBeforeItsInsert(string $environment): void
    {
        $this->boot($environment);
        $host = (new KernelUser())->setEmail('host@example.com')->setPassword('x');
        $this->em()->persist($host);
        $this->em()->flush();
        // Another request committed this account after this one's form was validated.
        $this->em()->getConnection()->insert('kernel_users', ['email' => 'ada@example.com', 'password' => 'x', 'roles' => '[]', 'newsletter' => 0]);

        try {
            $this->service(UserRegistrar::class)->register(['email' => 'ada@example.com', 'password' => 'secret-123'], 'default');
            self::fail('the identifier is taken');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('same unique value', $exception->getMessage());
        }

        self::assertTrue($this->em()->isOpen(), 'refused under the account lock, before the insert: the manager stays open');
        self::assertTrue($this->em()->contains($host), 'the application\'s entities stay managed');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));
    }

    #[DataProvider('modes')]
    public function testARefusedAcceptanceKeepsNothingAndLeavesTheApplicationsEntitiesManaged(string $environment): void
    {
        $this->boot($environment);
        $host = (new KernelUser())->setEmail('host@example.com')->setPassword('x');
        $this->em()->persist($host);
        $this->em()->flush();
        [$invitation, $hash] = $this->invitation('refuse@example.com');

        $outcome = $this->service(InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'refuse@example.com', 'password' => 'secret-123']);

        self::assertSame(InvitationAcceptance::REFUSED, $outcome->status);
        self::assertTrue($this->em()->contains($host), 'up to 1.49 the whole manager was cleared');
        self::assertTrue($this->em()->isOpen());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'refuse@example.com'"), 'no account');
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT accepted_at FROM auth_kit_invitation'), 'the invitation stays usable');
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());
    }

    #[DataProvider('modes')]
    public function testAnInvitationAcceptedMeanwhileIsNotAcceptedTwice(string $environment): void
    {
        $this->boot($environment);
        [$invitation, $hash] = $this->invitation('ada@example.com');
        // A concurrent acceptance committed first (its single-use claim).
        $this->em()->getConnection()->executeStatement("UPDATE auth_kit_invitation SET accepted_at = '2026-11-15 10:00:00', accepted_by = 'other'");

        $outcome = $this->service(InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);

        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $outcome->status);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_users'), 'no account for a used invitation');
        self::assertSame('other', $this->em()->getConnection()->fetchOne('SELECT accepted_by FROM auth_kit_invitation'));
    }

    #[DataProvider('modes')]
    public function testAnAcceptanceCreatesTheAccountAndClaimsTheInvitationInOneUnit(string $environment): void
    {
        $this->boot($environment);
        [$invitation, $hash] = $this->invitation('ada@example.com');

        $outcome = $this->service(InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);

        self::assertSame(InvitationAcceptance::ACCEPTED, $outcome->status);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT accepted_at FROM auth_kit_invitation'));
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());
    }

    private function boot(string $environment): void
    {
        self::bootKernel(['environment' => $environment]);
        $em     = $this->em();
        $schema = new SchemaTool($em);
        $all    = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($all);
        $schema->createSchema($all);
    }

    /**
     * @return array{Invitation, string}
     */
    private function invitation(string $email): array
    {
        $tokens     = new InvitationTokens();
        $hash       = $tokens->hash('token-' . $email);
        $now        = new DateTimeImmutable();
        $invitation = new Invitation('default', $email, null, 7, $now);
        $invitation->issue($hash, 7, $now);
        // Through AuthKit's repository: the private manager in `*_private`.
        $this->service(InvitationRepository::class)->save($invitation);

        return [$invitation, $hash];
    }

    private function em(): EntityManagerInterface
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
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
