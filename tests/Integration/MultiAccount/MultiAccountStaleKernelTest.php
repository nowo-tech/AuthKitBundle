<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\MultiAccount\Event\AccountRemovedEvent;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\MultiAccount\Twig\MultiAccountExtension;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function basename;
use function file_put_contents;
use function glob;
use function serialize;

/**
 * Account switcher with stale forms and changed accounts (1.34): one revoked session, a removed user, an expired
 * parked session, an emptied parked session, impersonators, anonymous sign-out-all, UserKit user removal.
 */
final class MultiAccountStaleKernelTest extends MultiAccountKernelTestCase
{
    public function testOneRevokedSessionDropsTheAccountFromTheList(): void
    {
        $this->twoAccounts();
        $sessions = $this->sessions();
        $member   = $this->user('member@example.com');
        // The registry row of the parked session (read with SQL: UserSessionManager::listFor() uses a deprecated ORM call).
        $publicId = (string) $this->em()->getConnection()->fetchOne('SELECT public_id FROM user_kit_session WHERE user_id = ? AND revoked_at IS NULL', ['member@example.com']);
        $sessions->revoke($member, $publicId);
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertSame(AccountRemovalReason::Revoked, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);
    }

    public function testStaleFormsOfAnAccountThatLeft(): void
    {
        $this->twoAccounts();
        [$switch, $signOut] = $this->forms('member@example.com');
        $this->signOutOf('member@example.com');
        $this->client->submit($switch);
        self::assertResponseRedirects('/accounts', 303);
        $this->client->submit($signOut);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARemovedUserLeaves(): void
    {
        $this->twoAccounts();
        [$switch] = $this->forms('member@example.com');
        $member   = $this->em()->find(KernelUser::class, 'member@example.com');
        self::assertInstanceOf(KernelUser::class, $member);
        $this->em()->remove($member);
        $this->em()->flush();
        $this->client->submit($switch);
        self::assertResponseRedirects('/accounts', 303);
        self::assertSame(['admin@example.com|active'], $this->accounts());
        $this->em()->persist((new KernelUser())->setEmail('member@example.com')->setPassword('member-password-123'));
        $this->em()->flush();
        $this->em()->clear();
        $this->client->submit($switch);
        self::assertResponseRedirects('/accounts', 303);
        self::assertSame(AccountRemovalReason::UserRemoved, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);
        self::assertSame(['admin@example.com|active'], $this->accounts());
    }

    public function testAnExpiredParkedSessionWithoutRememberMeLeaves(): void
    {
        $this->twoAccounts();
        [$switch] = $this->forms('member@example.com');
        $this->clock()->modify('+23 hours');
        $this->whoami();
        $this->clock()->modify('+2 hours');
        $this->client->submit($switch);
        self::assertResponseRedirects('/accounts', 303);
        self::assertSame(AccountRemovalReason::Expired, $this->eventsOf(AccountRemovedEvent::class)[0]->reason);
    }

    public function testAnEmptiedParkedSessionIsGone(): void
    {
        $this->twoAccounts();
        $current = (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $kernel  = self::$kernel;
        self::assertNotNull($kernel);
        foreach (glob($kernel->getCacheDir() . '/sessions/*.mocksess') ?: [] as $file) {
            if (basename($file, '.mocksess') !== $current) {
                file_put_contents($file, serialize([]));
            }
        }
        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/accounts', 303);
        self::assertStringStartsWith('admin@example.com|', $this->whoami());
    }

    public function testImpersonatorsCannotSwitchAndAnonymousSignOutAll(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('support@example.com', 'support-password-123');
        $this->client->request('GET', '/mfa-home?_switch_user=admin@example.com');
        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/accounts', 303);
        self::assertStringStartsWith('admin@example.com|', $this->whoami(), 'Still impersonating: the switcher is no impersonation path.');
        $this->client->request('GET', '/mfa-home?_switch_user=_exit');

        $this->add();
        $crawler = $this->client->request('GET', '/accounts');
        $this->client->submit($crawler->filter('[data-testid="auth-kit-account-sign-out-all"]')->closest('form')?->form() ?? self::fail('No sign-out-all.'));
        self::assertResponseRedirects('/login', 303);
        self::assertSame([], $this->accounts());
    }

    public function testARemovedParkedUserLeavesTheList(): void
    {
        $this->twoAccounts();
        $member = $this->em()->find(KernelUser::class, 'member@example.com');
        self::assertInstanceOf(KernelUser::class, $member);
        $this->em()->remove($member);
        $this->em()->flush();
        self::assertSame(['admin@example.com|active'], $this->accounts());
    }

    public function testAddIsRefusedWhileImpersonating(): void
    {
        $this->signIn('support@example.com', 'support-password-123');
        $add = $this->menu()->filter('[data-testid="auth-kit-account-add"]')->closest('form')?->form() ?? self::fail('No add.');
        $this->client->request('GET', '/mfa-home?_switch_user=member@example.com');
        $this->client->submit($add);
        self::assertResponseRedirects('/accounts', 303);
        self::assertStringStartsWith('member@example.com|', $this->whoami());
    }

    public function testUserRemovalInUserKitAndDirectCalls(): void
    {
        $this->twoAccounts();
        $this->sessions()->forgetUser($this->user('member@example.com'));
        self::assertSame(['admin@example.com|active'], $this->accounts());

        $subscriber = self::getContainer()->get(MultiAccountSubscriber::class);
        self::assertInstanceOf(MultiAccountSubscriber::class, $subscriber);
        $event = new LogoutEvent(new Request(), null);
        $subscriber->onLogout($event);
        self::assertNull($event->getResponse());

        $twig = self::getContainer()->get(MultiAccountExtension::class);
        self::assertInstanceOf(MultiAccountExtension::class, $twig);
        self::assertNull($twig->switcher('nope'));
        self::assertNull($twig->switcher(), 'No main request outside a request.');
    }

    public function testSwitchesAreRateLimited(): void
    {
        $this->twoAccounts();
        for ($i = 0; $i < 30; ++$i) {
            $this->switchTo($i % 2 === 0 ? 'member@example.com' : 'admin@example.com');
        }
        $this->switchTo('member@example.com');
        self::assertResponseRedirects('/accounts', 303);
        $this->client->followRedirect();
        self::assertStringContainsString('Too many attempts', (string) $this->client->getResponse()->getContent());
    }

    private function twoAccounts(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    /**
     * @return array{0: Form, 1: Form} switch and sign-out forms of an account
     */
    private function forms(string $identifier): array
    {
        $row = $this->menu()->filter('[data-testid="auth-kit-account"]')->reduce(static fn ($r): bool => $r->filter('.nowo-auth-kit-accounts__name')->text() === $identifier);

        return [$row->filter('form.nowo-auth-kit-accounts__switch')->form(), $row->filter('form.nowo-auth-kit-accounts__sign-out')->form()];
    }

    private function sessions(): UserSessionManager
    {
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);

        return $sessions;
    }
}
