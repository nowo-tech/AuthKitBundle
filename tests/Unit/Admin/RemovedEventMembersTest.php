<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Admin;

use Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent;
use Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaResetByAdminEvent;
use Nowo\AuthKitBundle\MultiAccount\AccountState;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountAddedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchingEvent;
use Nowo\AuthKitBundle\MultiAccount\SwitchOutcome;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginApprovedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginChallengeCreatedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginCompletedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginDeniedEvent;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * 2.0 (REQ-ARCH-001 rule 3): the framework- and entity-typed members of the events and results, deprecated since 1.46.0
 * (and the getters 1.55.0 added for them, deprecated from the start), are no longer public. The constructors are
 * unchanged (hosts still dispatch `CredentialsChangedEvent` with the user object) and the replacements keep working.
 */
final class RemovedEventMembersTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function properties(): iterable
    {
        $members = [
            AdminMagicLinkIssuedEvent::class    => ['issuer', 'target'],
            InvitationEvent::class              => ['actor'],
            InvitationAcceptedEvent::class      => ['user'],
            InvitationAcceptingEvent::class     => ['user'],
            MfaEvent::class                     => ['user'],
            MfaResetByAdminEvent::class         => ['actor'],
            MfaLoginCompletedEvent::class       => ['token', 'request'],
            AccountEvent::class                 => ['request'],
            AccountAddedEvent::class            => ['user'],
            AccountRemovedEvent::class          => ['user'],
            AccountSwitchedEvent::class         => ['from', 'to'],
            AccountSwitchingEvent::class        => ['from', 'to'],
            QrLoginChallengeCreatedEvent::class => ['challenge'],
            QrLoginApprovedEvent::class         => ['challenge', 'user'],
            QrLoginDeniedEvent::class           => ['challenge'],
            QrLoginCompletedEvent::class        => ['challenge', 'user'],
            CredentialsChangedEvent::class      => ['user'],
            AccountState::class                 => ['user'],
            SwitchOutcome::class                => ['user'],
            InvitationAcceptance::class         => ['user'],
        ];
        foreach ($members as $class => $properties) {
            foreach ($properties as $property) {
                yield $class . '::$' . $property => [$class, $property];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('properties')]
    public function testTheDeprecatedMemberIsNoLongerPublic(string $class, string $property): void
    {
        $reflection = new ReflectionProperty($class, $property);
        self::assertTrue($reflection->isPrivate(), $class . '::$' . $property);
        // The getter 1.55.0 added for it (deprecated from the start) is gone too.
        self::assertFalse((new ReflectionClass($class))->hasMethod($property), $class . '::' . $property . '()');
    }

    public function testTheAdminUserEventsLostGetUser(): void
    {
        self::assertFalse((new ReflectionClass(AdminUserCreatingEvent::class))->hasMethod('getUser'));
        self::assertFalse((new ReflectionClass(AdminUserCreatedEvent::class))->hasMethod('getUser'));
    }

    public function testTheReplacementsStillHandTheObject(): void
    {
        $user = new TestUser('owner@example.com');

        self::assertSame('owner@example.com', (new AdminUserCreatingEvent($user, 'default'))->userRef()->identifier);
        self::assertSame($user, (new AdminUserCreatedEvent($user, 'default', []))->userRef()->user());
        // Hosts dispatch this event with the user object: the constructor is unchanged.
        $changed = new CredentialsChangedEvent($user);
        self::assertSame($user, $changed->userRef()->user());
        self::assertTrue($changed->passwordChanged);
    }
}
