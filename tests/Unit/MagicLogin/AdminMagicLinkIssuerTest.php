<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuedEvent;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkRefusedException;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\NullMagicLoginNotifier;
use Nowo\AuthKitBundle\Security\LoginLinkHandlerLocator;
use Nowo\AuthKitBundle\Tests\Stub\PreferredLocaleTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class AdminMagicLinkIssuerTest extends TestCase
{
    public function testRefusesTheOwnAccountAndProtectedRoles(): void
    {
        $issuer = new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), new RequestStack(), $this->handler(0));
        $admin  = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $peer   = $this->user('peer@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $member = $this->user('member@example.com', ['ROLE_USER']);

        self::assertTrue($issuer->canIssue($admin, $member));
        self::assertFalse($issuer->canIssue($admin, $admin));
        self::assertFalse($issuer->canIssue($admin, $peer));
        self::assertSame(AdminMagicLinkIssuer::DEFAULT_LIFETIME, $issuer->lifetime());

        foreach ([[$admin, AdminMagicLinkRefusedException::OWN_ACCOUNT], [$peer, AdminMagicLinkRefusedException::PROTECTED_ROLE]] as [$target, $reason]) {
            try {
                $issuer->issue($admin, $target);
                self::fail('refused');
            } catch (AdminMagicLinkRefusedException $e) {
                self::assertSame($reason, $e->reason());
                self::assertSame($reason, $e->getMessage());
            }
        }

        $custom = new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), new RequestStack(), protectedRoles: ['ROLE_OPS']);
        self::assertTrue($custom->canIssue($admin, $peer), 'protected roles are configurable');
    }

    public function testRefusesWithoutAFirewallLoginLink(): void
    {
        $this->expectExceptionObject(new AdminMagicLinkRefusedException(AdminMagicLinkRefusedException::NOT_CONFIGURED));

        (new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), new RequestStack()))
            ->issue($this->user('admin@example.com', ['ROLE_ADMIN']), $this->user('member@example.com', ['ROLE_USER']));
    }

    /** SEC-004 L2: the link is signed by the login link handler of the target profile's firewall. */
    public function testUsesTheLoginLinkHandlerOfTheTargetProfileFirewall(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class, ['firewall' => 'members']);
        $locator  = new LoginLinkHandlerLocator(new ServiceLocator(['members' => fn (): LoginLinkHandlerInterface => $this->handler(3600)]));
        $admin    = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $member   = $this->user('member@example.com', ['ROLE_USER']);

        $issuer = new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), new RequestStack(), loginLinkHandlers: $locator, profileRegistry: $registry);
        self::assertStringStartsWith('https://example.test/', $issuer->issue($admin, $member)->getUrl());

        $this->expectExceptionObject(new AdminMagicLinkRefusedException(AdminMagicLinkRefusedException::NOT_CONFIGURED));
        (new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), new RequestStack(), $this->handler(3600), loginLinkHandlers: $locator))->issue($admin, $member);
    }

    public function testIssueReturnsTheLinkWithItsLifetimeAndDispatchesTheAuditEvent(): void
    {
        $events     = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event;

            return $event;
        });
        $stack = new RequestStack();
        $stack->push(Request::create('/admin/users'));
        $admin  = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->user('member@example.com', ['ROLE_USER']);

        $link = (new AdminMagicLinkIssuer(new NullMagicLoginNotifier(), $stack, $this->handler(1800), $dispatcher, lifetime: 1800))
            ->issue($admin, $member);

        self::assertSame('https://example.test/login/magic/check?u=1', $link->getUrl());
        self::assertCount(1, $events);
        self::assertInstanceOf(AdminMagicLinkIssuedEvent::class, $events[0]);
        self::assertSame($admin, $events[0]->issuer);
        self::assertSame($member, $events[0]->target);
        self::assertSame(AdminMagicLinkIssuedEvent::DELIVERY_SHOWN, $events[0]->delivery);
        self::assertEquals($link->getExpiresAt(), $events[0]->expiresAt);
    }

    public function testSendNotifiesInTheRecipientLocale(): void
    {
        $contexts = [];
        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->method('notify')->willReturnCallback(static function (MagicLoginNotificationContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (AdminMagicLinkIssuedEvent $event): bool => $event->delivery === AdminMagicLinkIssuedEvent::DELIVERY_EMAIL,
        ))->willReturnArgument(0);
        $member = new PreferredLocaleTestUser('member@example.com', 'es');

        $issuer = new AdminMagicLinkIssuer(
            $notifier,
            new RequestStack(),
            $this->handler(AdminMagicLinkIssuer::DEFAULT_LIFETIME),
            $dispatcher,
            new RecipientLocale(enabled: true, allowedLocales: ['en', 'es']),
        );
        $expires = $issuer->send($this->user('admin@example.com', ['ROLE_ADMIN']), $member);
        // Without a dispatcher the link still goes out (events are optional); an explicit recipient wins.
        (new AdminMagicLinkIssuer($notifier, new RequestStack(), $this->handler(AdminMagicLinkIssuer::DEFAULT_LIFETIME)))
            ->send($this->user('admin@example.com', ['ROLE_ADMIN']), $member, 'alias@example.com');

        self::assertCount(2, $contexts);
        self::assertSame('member@example.com', $contexts[0]->identifier);
        self::assertSame('es', $contexts[0]->recipientLocale);
        self::assertSame('https://example.test/login/magic/check?u=1', $contexts[0]->loginUrl);
        self::assertEquals($expires, $contexts[0]->expiresAt);
        self::assertSame('alias@example.com', $contexts[1]->identifier);
    }

    private function handler(int $lifetime): LoginLinkHandlerInterface
    {
        $handler = $this->createMock(LoginLinkHandlerInterface::class);
        $handler->method('createLoginLink')->willReturnCallback(
            static function (object $user, ?Request $request, ?int $given) use ($lifetime): LoginLinkDetails {
                self::assertSame($lifetime, $given);

                return new LoginLinkDetails('https://example.test/login/magic/check?u=1', new DateTimeImmutable('+' . $lifetime . ' seconds'));
            },
        );

        return $handler;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $email, array $roles): TestUser
    {
        $user = new TestUser($email);
        $user->setRoles($roles);

        return $user;
    }
}
