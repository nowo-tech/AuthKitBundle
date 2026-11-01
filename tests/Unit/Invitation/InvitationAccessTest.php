<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Invitation\Security\RoleInvitationsAccessChecker;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * REQ-UI-002 access to the invitation administration: the role checker (any role; empty list denies — P1), the guard
 * (signed-in user + the profile checker; allow_unauthenticated opens it) and the grantable roles of the inviter.
 */
final class InvitationAccessTest extends TestCase
{
    public function testRoleCheckerGrantsAnyConfiguredRoleAndDeniesAnEmptyList(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_HR');
        $user = new TestUser();

        self::assertTrue((new RoleInvitationsAccessChecker($authorization, ['ROLE_ADMIN', 'ROLE_HR']))->canManage($user, 'default'));
        self::assertFalse((new RoleInvitationsAccessChecker($authorization, ['ROLE_ADMIN', '']))->canManage($user, 'default'));
        self::assertFalse((new RoleInvitationsAccessChecker($authorization, []))->canManage($user, 'default'));
    }

    public function testGuard(): void
    {
        $user    = new TestUser();
        $storage = new TokenStorage();
        $allow   = $this->createMock(InvitationsAccessCheckerInterface::class);
        $allow->method('canManage')->willReturn(true);
        $checkers      = new ServiceLocator(['default' => static fn (): InvitationsAccessCheckerInterface => $allow, 'odd' => static fn (): object => new stdClass()]);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_EDITOR');
        $guard = new InvitationsAccessGuard($checkers, $storage, $authorization, ['demo' => true]);

        self::assertNull($guard->user());
        self::assertFalse($guard->canManage('default'), 'Anonymous visitors are refused.');
        self::assertTrue($guard->canManage('demo'), 'allow_unauthenticated (DEV/DEMO only).');
        self::assertSame([], $guard->grantableRoles(['ROLE_EDITOR']));

        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        self::assertSame($user, $guard->user());
        self::assertTrue($guard->canManage('default'));
        self::assertFalse($guard->canManage('unknown'), 'No checker for the profile: refused.');
        self::assertFalse($guard->canManage('odd'), 'A service that is not a checker: refused.');
        self::assertSame(['ROLE_EDITOR'], $guard->grantableRoles(['ROLE_EDITOR', 'ROLE_SUPER_ADMIN']));

        self::assertSame([], (new InvitationsAccessGuard($checkers, $storage))->grantableRoles(['ROLE_EDITOR']), 'No authorization checker: no role.');
    }
}
