<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent;
use Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaEnabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaResetByAdminEvent;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
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
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function hash;

/**
 * The 1.46 framework-free accessors of the events and result VOs (REQ-ARCH-001 rules 3 and 4).
 */
final class FrameworkFreeEventAccessorsTest extends TestCase
{
    private function user(string $email, int $id): TestUser
    {
        $user = new TestUser($email);
        $user->setId($id);
        $user->setRoles(['ROLE_USER']);

        return $user;
    }

    private function assertRef(TestUser $user, ?AuthUserRef $ref): void
    {
        self::assertInstanceOf(AuthUserRef::class, $ref);
        self::assertSame($user, $ref->user());
        self::assertSame(TestUser::class, $ref->userClass);
        self::assertSame((string) $user->getId(), $ref->id);
        self::assertSame($user->getUserIdentifier(), $ref->identifier);
    }

    private function snapshot(): InvitationSnapshot
    {
        $now = new DateTimeImmutable();

        return new InvitationSnapshot('pid', 'default', 'a@example.com', [], [], null, null, null, 7, $now, $now, 1, $now, null, null, null, null, InvitationStatus::Pending);
    }

    private function challenge(): QrLoginChallenge
    {
        return new QrLoginChallenge('qr-id', 'CODE0001', hash('sha256', 'c'), hash('sha256', 'i'), hash('sha256', 'u'), 'Chrome', hash('sha256', 't'), new DateTimeImmutable('+60 seconds'));
    }

    public function testAdminEvents(): void
    {
        $user = $this->user('admin@example.com', 1);

        $this->assertRef($user, (new AdminUserCreatedEvent($user, 'default', ['ROLE_ADMIN']))->userRef());
        $this->assertRef($user, (new AdminUserCreatingEvent($user, 'default'))->userRef());

        $issuer = $this->user('issuer@example.com', 2);
        $event  = new AdminMagicLinkIssuedEvent($issuer, $user, AdminMagicLinkIssuedEvent::DELIVERY_SHOWN, new DateTimeImmutable());
        $this->assertRef($issuer, $event->issuerRef());
        $this->assertRef($user, $event->targetRef());
    }

    public function testInvitationEvents(): void
    {
        $actor = $this->user('actor@example.com', 3);
        $user  = $this->user('new@example.com', 4);
        $now   = new DateTimeImmutable();

        $this->assertRef($actor, (new InvitationCreatedEvent($this->snapshot(), $actor, $now, InvitationCreatedEvent::DELIVERY_EMAIL))->actorRef());
        self::assertNull((new InvitationRevokedEvent($this->snapshot(), null, $now))->actorRef());

        $accepted = new InvitationAcceptedEvent($this->snapshot(), $user, $now);
        $this->assertRef($user, $accepted->userRef());
        $this->assertRef($user, $accepted->actorRef());

        $this->assertRef($user, (new InvitationAcceptingEvent($this->snapshot(), $now, $user))->userRef());
        self::assertNull((new InvitationAcceptingEvent($this->snapshot(), $now))->userRef());

        $this->assertRef($user, InvitationAcceptance::accepted($user, $this->snapshot())->userRef());
        self::assertNull(InvitationAcceptance::failed(InvitationAcceptance::EXPIRED)->userRef());
    }

    public function testMfaEvents(): void
    {
        $user  = $this->user('mfa@example.com', 5);
        $actor = $this->user('admin@example.com', 6);

        $this->assertRef($user, (new MfaEnabledEvent('default', $user))->userRef());

        $reset = new MfaResetByAdminEvent('default', $user, $actor);
        $this->assertRef($user, $reset->userRef());
        $this->assertRef($actor, $reset->actorRef());

        $request = Request::create('/mfa/check', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.9']);
        $event   = new MfaLoginCompletedEvent('default', $user, new UsernamePasswordToken($user, 'main', ['ROLE_USER']), $request, 'password');
        self::assertSame('main', $event->firewall());
        $context = $event->requestContext();
        self::assertSame('/mfa/check', $context->path);
        self::assertSame('10.0.0.9', $context->clientIp);
        self::assertSame('main', $context->firewall);
        self::assertSame('default', $context->profile);
        $this->assertRef($user, $event->userRef());

        self::assertNull((new MfaLoginCompletedEvent('default', $user, new NullToken(), $request, 'password'))->firewall());
        $blank = new class extends AbstractToken {
            public function getFirewallName(): string
            {
                return '';
            }
        };
        self::assertNull((new MfaLoginCompletedEvent('default', $user, $blank, $request, 'password'))->firewall());
    }

    public function testAccountEvents(): void
    {
        $from    = $this->user('from@example.com', 7);
        $to      = $this->user('to@example.com', 8);
        $request = Request::create('/accounts/switch');

        $added = new AccountAddedEvent('default', 'acc1', $request, $to);
        $this->assertRef($to, $added->userRef());
        self::assertSame('/accounts/switch', $added->requestContext()->path);
        self::assertSame('default', $added->requestContext()->profile);
        self::assertNull($added->requestContext()->firewall);

        $this->assertRef($to, (new AccountRemovedEvent('default', 'acc1', $request, $to, AccountRemovalReason::SignedOut))->userRef());
        self::assertNull((new AccountRemovedEvent('default', 'acc1', $request, null, AccountRemovalReason::SignedOut))->userRef());

        $switched = new AccountSwitchedEvent('default', 'acc1', $request, $from, $to);
        $this->assertRef($from, $switched->fromRef());
        $this->assertRef($to, $switched->toRef());
        self::assertNull((new AccountSwitchedEvent('default', 'acc1', $request, null, $to))->fromRef());

        $switching = new AccountSwitchingEvent('default', 'acc1', $request, $from, $to);
        $this->assertRef($from, $switching->fromRef());
        $this->assertRef($to, $switching->toRef());
        self::assertNull((new AccountSwitchingEvent('default', 'acc1', $request, null, $to))->fromRef());
        $switching->refuse('no', ['a' => 'b']);
        self::assertSame('no', $switching->refusal());
        self::assertTrue($switching->isPropagationStopped());

        $this->assertRef($to, (new AccountState('acc1', $to, new AccountPresentation('To', 'to@example.com'), AccountState::ACTIVE))->userRef());
        $this->assertRef($to, SwitchOutcome::switched($to)->userRef());
        self::assertNull(SwitchOutcome::gone()->userRef());
    }

    public function testQrEvents(): void
    {
        $user      = $this->user('qr@example.com', 9);
        $challenge = $this->challenge();

        $approved  = new QrLoginApprovedEvent($challenge, $user, 'default');
        $completed = new QrLoginCompletedEvent($challenge, $user, 'default');
        foreach ([$approved, $completed, new QrLoginChallengeCreatedEvent($challenge, 'default'), new QrLoginDeniedEvent($challenge, 'default')] as $event) {
            self::assertSame('qr-id', $event->challengeId());
            self::assertSame('CODE0001', $event->challengeRef()->publicCode);
        }
        $this->assertRef($user, $approved->userRef());
        $this->assertRef($user, $completed->userRef());
    }

    public function testCredentialsChangedEvent(): void
    {
        $user = $this->user('c@example.com', 10);

        $this->assertRef($user, (new CredentialsChangedEvent($user))->userRef());
    }
}
