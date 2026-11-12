<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountAddedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountSwitchedEvent;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Several accounts in one browser (1.34): add a second account through its full sign-in and MFA, switch without a
 * password, separate sessions, revocation, sign out one or all, refused disabled account.
 */
class MultiAccountFlowKernelTest extends MultiAccountKernelTestCase
{
    public function testAddASecondAccountWithMfaAndSwitch(): void
    {
        $secret = $this->enableMfa('support@example.com');
        $this->signIn('member@example.com', 'member-password-123');
        self::assertStringStartsWith('member@example.com|', $this->whoami());
        self::assertNull($this->vaultCookie(), 'No vault until a second account is added.');

        $this->add();
        self::assertStringStartsWith('anonymous|', $this->whoami(), 'Add another account opens an empty session.');
        self::assertNotNull($this->vaultCookie());

        $this->signIn('support@example.com', 'support-password-123');
        self::assertResponseRedirects('/login/mfa');
        self::assertCount(1, $this->eventsOf(AccountAddedEvent::class), 'Only the parked account so far: the pending sign-in is not enrolled.');
        $this->challenge(['code' => $this->code($secret)]);
        self::assertStringStartsWith('support@example.com|', $this->whoami());
        self::assertCount(2, $this->eventsOf(AccountAddedEvent::class));
        self::assertSame(['support@example.com|active', 'member@example.com|signed_in'], $this->accounts());

        $before = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/mfa-home', 303);
        self::assertSame('member@example.com|' . UsernamePasswordToken::class, $this->whoami(), 'No password, no MFA: the original token is back.');
        self::assertNotSame($before, $this->client->getCookieJar()->get('MOCKSESSID')?->getValue());
        $switched = $this->eventsOf(AccountSwitchedEvent::class);
        self::assertSame('support@example.com', $switched[0]->from?->getUserIdentifier());
        self::assertSame('member@example.com', $switched[0]->to->getUserIdentifier());

        $this->switchTo('support@example.com');
        self::assertStringStartsWith('support@example.com|', $this->whoami());
        self::assertSame(['support@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    public function testRevokingOneAccountDropsOnlyThatAccount(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());

        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);
        $sessions->revokeAll($this->user('member@example.com'));
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
    }

    public function testSignOutOneThenAll(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->add();
        $this->signIn('support@example.com', 'support-password-123');
        self::assertCount(3, $this->accounts());

        $this->signOutOf('admin@example.com');
        self::assertSame(['support@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        self::assertSame(AccountRemovalReason::SignedOut, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);

        $crawler = $this->menu();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-account-sign-out-all"]')->closest('form')?->form() ?? self::fail('No sign out all.'));
        self::assertStringStartsWith('anonymous|', $this->whoami());
        self::assertSame([], $this->accounts());
    }

    public function testADisabledAccountIsRefused(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $member = $this->user('member@example.com');
        $member->setRoles(['ROLE_DISABLED']);
        $this->em()->flush();

        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/accounts', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your account is disabled.', $crawler->text());
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertSame(AccountRemovalReason::Refused, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);
    }

    public function testTheNormalSignOutEndsEveryAccount(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->client->request('GET', '/logout');
        self::assertSame([], $this->accounts());
        self::assertStringStartsWith('anonymous|', $this->whoami());
    }
}
