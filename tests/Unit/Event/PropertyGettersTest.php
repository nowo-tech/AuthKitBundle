<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Event;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationOpeningEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationsPurgedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengeFailedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaDisabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaEnabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLockedOutEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodesRegeneratedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodeUsedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaResetByAdminEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDeviceAddedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\MultiAccount\AccountState;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountAddedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchingEvent;
use Nowo\AuthKitBundle\MultiAccount\SwitchOutcome;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginApprovedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginChallengeCreatedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginCompletedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginDeniedEvent;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

use function class_exists;
use function enum_exists;
use function interface_exists;
use function is_subclass_of;
use function sprintf;

/**
 * 1.55.0: every public property of the AuthKit events and result objects has a getter of the same name
 * (`existingAccount` → `isExistingAccount()`) returning the property's value, so listeners can stop reading the
 * properties before 2.0 makes the deprecated ones private.
 */
final class PropertyGettersTest extends TestCase
{
    /** Property => getter, where the getter is not the property's name. */
    private const GETTERS = ['existingAccount' => 'isExistingAccount'];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classes(): iterable
    {
        foreach ([
            AdminMagicLinkIssuedEvent::class, InvitationAcceptedEvent::class, InvitationAcceptingEvent::class,
            InvitationCreatedEvent::class, InvitationResentEvent::class, InvitationRevokedEvent::class,
            InvitationLinkRejectedEvent::class, InvitationOpeningEvent::class, InvitationsPurgedEvent::class,
            InvitationAcceptance::class, MfaChallengeFailedEvent::class, MfaChallengePassedEvent::class,
            MfaDisabledEvent::class, MfaEnabledEvent::class, MfaLockedOutEvent::class, MfaLoginCompletedEvent::class,
            MfaRecoveryCodesRegeneratedEvent::class, MfaRecoveryCodeUsedEvent::class, MfaResetByAdminEvent::class,
            MfaTrustedDeviceAddedEvent::class, MfaTrustedDevicesForgottenEvent::class, AccountState::class,
            SwitchOutcome::class, AccountAddedEvent::class, AccountRemovedEvent::class, AccountSwitchedEvent::class,
            AccountSwitchingEvent::class, QrLoginApprovedEvent::class, QrLoginChallengeCreatedEvent::class,
            QrLoginCompletedEvent::class, QrLoginDeniedEvent::class, CredentialsChangedEvent::class,
        ] as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testEveryPublicPropertyHasAGetterReturningItsValue(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $object     = $reflection->newInstanceWithoutConstructor();
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        self::assertNotSame([], $properties);
        foreach ($properties as $property) {
            $value = $this->sample($property);
            $this->initialize($object, $property, $value);
        }
        foreach ($properties as $property) {
            $name   = $property->getName();
            $getter = self::GETTERS[$name] ?? $name;
            self::assertTrue($reflection->hasMethod($getter), sprintf('%s::%s() for the property $%s', $class, $getter, $name));
            self::assertTrue($reflection->getMethod($getter)->isPublic());
            self::assertSame($property->getValue($object), $object->{$getter}(), sprintf('%s::%s()', $class, $getter));
        }
    }

    private function initialize(object $object, ReflectionProperty $property, mixed $value): void
    {
        $name = $property->getName();
        // Readonly properties are initialised from their declaring class's scope.
        Closure::bind(function () use ($name, $value): void {
            $this->{$name} = $value;
        }, $object, $property->getDeclaringClass()->getName())();
    }

    private function sample(ReflectionProperty $property): mixed
    {
        $type = $property->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return match ($type->getName()) {
            'string'                 => 'value-of-' . $property->getName(),
            'int'                    => 42,
            'bool'                   => true,
            'array'                  => ['key' => 'value'],
            DateTimeImmutable::class => new DateTimeImmutable('2026-11-21 10:00:00'),
            Request::class           => Request::create('/sample'),
            default                  => $this->object($type->getName()),
        };
    }

    private function object(string $class): object
    {
        if (enum_exists($class) && is_subclass_of($class, BackedEnum::class)) {
            return $class::cases()[0];
        }
        if (interface_exists($class)) {
            return $this->createStub($class);
        }
        self::assertTrue(class_exists($class), $class);

        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
