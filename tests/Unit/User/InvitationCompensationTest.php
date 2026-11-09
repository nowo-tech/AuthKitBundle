<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Stub\InMemoryHostUsers;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * JW-A2: an invitation refused by a grant (or failing later) leaves no usable account when the host writer stores
 * outside the bundle's transaction: the acceptor removes it again.
 */
final class InvitationCompensationTest extends TestCase
{
    public function testAnInvitationRefusedByAGrantLeavesNoAccount(): void
    {
        $users = new InMemoryHostUsers();

        $outcome = $this->attempt($users, new NullLogger());

        self::assertSame(InvitationAcceptance::REFUSED, $outcome->status);
        self::assertSame(['add', 'remove'], $users->calls);
        self::assertSame([], $users->users);
    }

    public function testAFailingRemovalIsLoggedAndTheRefusalStays(): void
    {
        $users             = new InMemoryHostUsers();
        $users->failRemove = true;
        $logger            = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not remove'));

        self::assertSame(InvitationAcceptance::REFUSED, $this->attempt($users, $logger)->status);
    }

    private function attempt(InMemoryHostUsers $users, LoggerInterface $logger): InvitationAcceptance
    {
        $now        = new DateTimeImmutable('2026-11-09 10:00:00');
        $tokens     = new InvitationTokens();
        $hash       = $tokens->hash('token');
        $invitation = new Invitation('default', 'seat@example.com', null, 7, $now);
        $invitation->issue($hash, 7, $now);

        $repository = $this->createMock(InvitationRepository::class);
        $repository->method('claim')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('isOpen')->willReturn(true);

        $registry  = ProfileRegistryFactory::single(HostDomainUser::class, ['registration_mode' => 'invite_only', 'invitations' => ['mode' => 'enabled']]);
        $registrar = new UserRegistrar(
            $registry,
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            userFactory: $users,
            userWriter: $users,
            userLookup: $users,
        );
        $grant = new class implements InvitationGrantInterface {
            public function grant(InvitationSnapshot $invitation, UserInterface $user): void
            {
                throw InvitationRefusedException::because(new InvitationRefusal('host.no_seat_left'));
            }
        };

        $acceptor = new InvitationAcceptor(
            $repository,
            $registry,
            $registrar,
            $em,
            new MockClock($now),
            $tokens,
            [$grant],
            logger: $logger,
            userWriter: $users,
        );

        return $acceptor->attempt($invitation, $hash, ['email' => 'seat@example.com', 'password' => 'pw']);
    }
}
