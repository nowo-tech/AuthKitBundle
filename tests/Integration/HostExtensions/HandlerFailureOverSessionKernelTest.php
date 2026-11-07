<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use DateTimeImmutable;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\MultiAccount\Twig\MultiAccountExtension;
use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\MultiAccountKernelTestCase;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function json_decode;
use function parse_str;
use function parse_url;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * GY-L6: a handler signs someone in and then fails, over a session that existed before the callback. That session goes
 * on, the other accounts stay, and nothing of the failed account is reachable. Real firewall, UserKit, MFA, switcher.
 */
final class HandlerFailureOverSessionKernelTest extends MultiAccountKernelTestCase
{
    private const FAILED = 'failed|google|failed';

    protected static function getKernelClass(): string
    {
        return MultiAccountHandlerTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em()->persist((new SocialLoginCredential())->setProvider('google')->setLabel('Google')->setEnabled(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder'));
        $this->em()->flush();
    }

    public function testAnEarlierSessionOfAnotherUserGoesOnAndKeepsNothingOfTheFailedOne(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');
        $signedInAt = $this->sessionAttributes()[MfaEnrolment::SIGNED_IN_AT] ?? null;
        self::assertIsInt($signedInAt);
        $sessionBefore = $this->sessionId();
        $this->clock()->sleep(120);

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertSame(0, $this->sessionRows('support@example.com'), 'Gone at once, even if the visitor never comes back.');
        self::assertStringStartsWith('member@example.com|', $this->home());
        self::assertNotSame($sessionBefore, $this->sessionId(), 'The session id moved on.');

        $attributes = $this->sessionAttributes();
        self::assertSame($signedInAt, $attributes[MfaEnrolment::SIGNED_IN_AT], 'The failed sign-in must not refresh "recent sign-in".');
        self::assertArrayNotHasKey(PendingStepCallbackHandler::SESSION_KEY, $attributes, 'handleFailure() cleared it.');

        // UserKit: one row, of the earlier account, under the current session; none of the failed account.
        self::assertSame(1, $this->sessionRows('member@example.com'));
        self::assertSame(0, $this->sessionRows('support@example.com'));
        $markers = $this->sessionAttributes()[UserSessionManager::ATTRIBUTE] ?? [];
        self::assertIsArray($markers);
        self::assertCount(1, $markers);

        // Still signed in on later requests, across a kernel reboot.
        $this->client->enableReboot();
        self::assertStringStartsWith('member@example.com|', $this->home());
        self::assertStringStartsWith('member@example.com|', $this->home());
    }

    public function testTheOtherAccountsStayInTheSwitcher(): void
    {
        $this->twoAccounts();

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        self::assertSame(1, $this->vaultRows('admin@example.com'));
        self::assertSame(1, $this->vaultRows('member@example.com'));
        self::assertStringStartsWith('admin@example.com|', $this->home());

        // The parked account still comes back.
        $this->switchTo('member@example.com');
        self::assertStringStartsWith('member@example.com|', $this->home());
    }

    public function testAFailedAccountThatWasParkedLeavesThisBrowserOnly(): void
    {
        $this->twoAccounts();

        $this->failingSignIn('member@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertSame(['admin@example.com|active'], $this->accounts());
        self::assertSame(0, $this->vaultRows('member@example.com'));
        self::assertStringStartsWith('admin@example.com|', $this->home());
    }

    public function testFailingAsTheSameUserKeepsItsEntry(): void
    {
        $this->twoAccounts();

        $this->failingSignIn('admin@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
        self::assertStringStartsWith('admin@example.com|', $this->home());
    }

    public function testAPendingSecondFactorLeavesAnOlderEntryOfThatAccountAlone(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('support@example.com', 'support-password-123');
        $this->enableMfa('member@example.com');

        $this->failingSignIn('member@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertSame(1, $this->vaultRows('member@example.com'), 'A pending sign-in never enrolled: its entry is older.');
        self::assertStringStartsWith('support@example.com|', $this->home());
        self::assertStringNotContainsString('Pending', $this->home());
    }

    public function testNoRememberMeCookieOfTheFailedAccountLeaves(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->handler()->rememberMe = true;

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            self::assertFalse($cookie->getName() === 'REMEMBERME' && (string) $cookie->getValue() !== '', 'A live remember-me cookie of the failed account left.');
        }
    }

    public function testAnAnonymousVisitorStaysAnonymousWithoutARememberMeCookieAndKeepsTheOtherAccounts(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->handler()->rememberMe = true;

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            self::assertFalse($cookie->getName() === 'REMEMBERME' && (string) $cookie->getValue() !== '', 'A live remember-me cookie of the failed account left.');
        }
        self::assertStringNotContainsString('@example.com|', $this->home());
        self::assertSame(1, $this->vaultRows('member@example.com'), 'The parked account stays.');
        self::assertSame(0, $this->vaultRows('support@example.com'));
    }

    public function testAnHttpExceptionKeepsItsStatusAndTheEarlierSession(): void
    {
        $this->signIn('member@example.com', 'member-password-123');

        $this->failingSignIn('support@example.com', new NotFoundHttpException('refused'));

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('member@example.com|', $this->home());
        self::assertSame(0, $this->sessionRows('support@example.com'));
    }

    /** GY-I10 with an earlier session: the handler signed out by itself, so nothing is restored. */
    public function testAHandlerThatSignedOutByItselfIsNotRestored(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->handler()->signOutFirst = true;
        $this->client->request('GET', '/test/pending');
        $state                  = $this->startAuthorization();
        $this->handler()->throw = new RuntimeException('refused');

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('@example.com|', $this->home());
    }

    /** GY-L7: signed out everywhere while the provider was answering: the earlier session is not brought back. */
    public function testASessionRevokedDuringTheExchangeIsNotRestored(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');
        $member   = $this->user('member@example.com');
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);
        $provider = self::getContainer()->get(SocialProviderResponses::class);
        self::assertInstanceOf(SocialProviderResponses::class, $provider);
        $provider->whileAnswering = static function () use ($sessions, $member): void {
            $sessions->revokeAll($member);
        };

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('@example.com|', $this->home());
    }

    /** GY-L8: the vault fails during the rollback. The failure path answers, and the next request is the earlier account. */
    public function testARollbackFailingOnTheDatabaseLeavesTheFailedAccountSignedOut(): void
    {
        $this->twoAccounts();
        $this->handler()->dropTable = AccountVaultEntry::TABLE;

        $this->failingSignIn('support@example.com');
        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());

        $this->recreateVaultTable();
        $this->handler()->dropTable = null;
        self::assertStringStartsWith('admin@example.com|', $this->home());
    }

    /** GY-L8: the firewall's logout listeners fail on the anonymous path. Nobody stays signed in. */
    public function testAFailingLogoutOnTheAnonymousPathLeavesNobodySignedIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->handler()->dropTable = AccountVaultEntry::TABLE;

        $this->failingSignIn('support@example.com');
        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());

        $this->recreateVaultTable();
        $this->handler()->dropTable = null;
        self::assertStringNotContainsString('@example.com|', $this->home());
    }

    /** GY-I16: same user, so the login itself does not migrate: only the rollback moves the id. */
    public function testTheRollbackMovesTheSessionId(): void
    {
        $this->twoAccounts();
        $before = $this->sessionId();

        $this->failingSignIn('admin@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertNotSame($before, $this->sessionId());
    }

    /**
     * GY-I16: a listener throws before the session strategy and UserKit ran, so the earlier row and marker are still
     * there. They must follow the new id, or the early check signs the session out on the next request.
     */
    public function testTheEarlierRowFollowsTheNewId(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');
        $listener = self::getContainer()->get(ThrowingLoginListener::class);
        self::assertInstanceOf(ThrowingLoginListener::class, $listener);
        $listener->armed = true;

        $this->failingSignIn('support@example.com');
        $listener->armed = false;

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);
        self::assertTrue($sessions->sessionState((string) $this->sessionId(), $this->user('member@example.com'))->isLive(), 'The row followed the new id at once, so a revocation before the next request still finds it.');
        self::assertStringStartsWith('member@example.com|', $this->home());
        self::assertSame(1, $this->sessionRows('member@example.com'));
        $markers = $this->sessionAttributes()[UserSessionManager::ATTRIBUTE] ?? null;
        self::assertIsArray($markers);
        self::assertIsArray($markers['main'] ?? null);
        self::assertSame($sessions->hash((string) $this->sessionId()), $markers['main']['h']);
    }

    /** HU-L1 (test A8): a first link in add mode replaces the password, and the account just added stays in the switcher. */
    public function testAFirstLinkInAddModeKeepsTheAccountJustAdded(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->provider()->email = 'admin@example.com';

        $this->defaultCallback();

        self::assertSame(['admin@example.com', 'member@example.com'], $this->vaultIdentifiers());
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    /** HU-L1: its entries in other browsers still go on the password change. */
    public function testAFirstLinkInAddModeForgetsTheOtherBrowsers(): void
    {
        $store = self::getContainer()->get(AccountVaultStore::class);
        self::assertInstanceOf(AccountVaultStore::class, $store);
        $now = new DateTimeImmutable('2026-11-03 10:00:00');
        $store->insert(bin2hex(random_bytes(16)), 'default', 'admin@example.com', hash('sha256', 'another browser'), null, $now, $now->modify('+1 day'));
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->provider()->email = 'admin@example.com';

        $this->defaultCallback();

        self::assertSame(1, $this->vaultRows('admin@example.com'));
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    /** HU-L1: only `password_changed` keeps it: a sign-out everywhere in the same request removes it. */
    public function testASignOutEverywhereDuringTheSignInStillForgetsIt(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->provider()->email    = 'admin@example.com';
        $this->lateListener()->late = function (): void {
            $this->sessions()->revokeAll($this->user('admin@example.com'));
        };

        $this->defaultCallback();

        self::assertSame(0, $this->vaultRows('admin@example.com'));
    }

    /** GY-L7: revoked while the handler runs, before its sign-in deleted the row: the session comes back revoked. */
    public function testASessionRevokedDuringTheHandlerIsSignedOut(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');
        $member                        = $this->user('member@example.com');
        $this->handler()->beforeSignIn = function () use ($member): void {
            $this->sessions()->revokeAll($member);
        };

        $this->failingSignIn('support@example.com');

        self::assertSame(self::FAILED, $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('@example.com|', $this->home());
    }

    /** GY-L7: the earlier session has its row at once, so a sign-out everywhere right after the rollback reaches it. */
    public function testASignOutEverywhereRightAfterTheRollbackReachesTheSession(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');

        $this->failingSignIn('support@example.com');

        self::assertSame(1, $this->sessions()->revokeAll($this->user('member@example.com')));
        self::assertStringNotContainsString('@example.com|', $this->home());
    }

    /** HP-I2: the rollback brings the row back and the session follows it: one row, not a second one. */
    public function testALateFailureInTheDefaultFlowLeavesOneRow(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/mfa-home');
        $this->provider()->email    = 'support@example.com';
        $this->lateListener()->late = static function (): never {
            throw new RuntimeException('listener down');
        };

        $this->defaultCallback();

        self::assertResponseRedirects('/login');
        self::assertStringStartsWith('member@example.com|', $this->home());
        self::assertSame(1, $this->sessionRows('member@example.com'));
        self::assertSame('support-password-123', $this->user('support@example.com')->getPassword());
    }

    /** HU-I2: a `password_changed` listener runs after the commit, so its failure undoes nothing. */
    public function testAFailingPasswordChangedListenerDoesNotUndoTheFirstLink(): void
    {
        $this->provider()->email         = 'support@example.com';
        $this->lateListener()->onRevoked = new RuntimeException('listener down');

        $this->defaultCallback();

        self::assertResponseStatusCodeSame(500);
        $this->lateListener()->onRevoked = null;
        self::assertNotSame('support-password-123', $this->user('support@example.com')->getPassword());
        self::assertSame(1, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
    }

    /** GY-I11: rendering the field writes nothing to the session. */
    public function testTheTargetPathFieldCreatesNoCsrfToken(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $before = $this->csrfKeys();
        $this->client->request('GET', '/test/session');
        $request = $this->client->getRequest();
        $stack   = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $extension = self::getContainer()->get(MultiAccountExtension::class);
        self::assertInstanceOf(MultiAccountExtension::class, $extension);

        $view = $extension->targetPathField('/mfa-home', 'abc');

        self::assertSame('_target_path', $view->vars['full_name']);
        self::assertSame('/mfa-home', $view->vars['value']);
        self::assertSame('_target_path_abc', $view->vars['id']);
        self::assertSame([], array_filter(array_keys($request->getSession()->all()), static fn (string $name): bool => str_contains($name, 'csrf') && !in_array($name, $before, true)));
    }

    /**
     * @return list<string>
     */
    private function csrfKeys(): array
    {
        return array_values(array_filter(array_keys($this->sessionAttributes()), static fn (string $name): bool => str_contains($name, 'csrf')));
    }

    private function recreateVaultTable(): void
    {
        (new SchemaTool($this->em()))->createSchema([$this->em()->getClassMetadata(AccountVaultEntry::class)]);
    }

    private function defaultCallback(): void
    {
        $state = $this->startAuthorization();
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
    }

    /**
     * @return list<string>
     */
    private function vaultIdentifiers(): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT user_identifier FROM ' . AccountVaultEntry::TABLE . ' ORDER BY user_identifier');
    }

    private function provider(): SocialProviderResponses
    {
        $provider = self::getContainer()->get(SocialProviderResponses::class);
        self::assertInstanceOf(SocialProviderResponses::class, $provider);

        return $provider;
    }

    private function lateListener(): ThrowingLoginListener
    {
        $listener = self::getContainer()->get(ThrowingLoginListener::class);
        self::assertInstanceOf(ThrowingLoginListener::class, $listener);

        return $listener;
    }

    private function sessions(): UserSessionManager
    {
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);

        return $sessions;
    }

    private function twoAccounts(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->add();
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertSame(['admin@example.com|active', 'member@example.com|signed_in'], $this->accounts());
    }

    private function failingSignIn(string $email, ?Throwable $exception = null): void
    {
        $this->client->request('GET', '/test/pending');
        $state                = $this->startAuthorization();
        $handler              = $this->handler();
        $handler->signInFirst = true;
        $handler->signInAs    = $email;
        $handler->throw       = $exception ?? new RuntimeException('refused');

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
    }

    private function startAuthorization(): string
    {
        $this->client->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    private function home(): string
    {
        $this->client->request('GET', '/mfa-home');

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionAttributes(): array
    {
        $this->client->request('GET', '/test/session');
        $attributes = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($attributes);

        return $attributes;
    }

    private function sessionId(): ?string
    {
        return $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
    }

    private function vaultRows(string $identifier): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . AccountVaultEntry::TABLE . ' WHERE user_identifier = ?', [$identifier]);
    }

    private function sessionRows(string $email): int
    {
        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);

        return count($sessions->listFor($this->user($email)));
    }

    private function handler(): PendingStepCallbackHandler
    {
        $handler = self::getContainer()->get(PendingStepCallbackHandler::class);
        self::assertInstanceOf(PendingStepCallbackHandler::class, $handler);

        return $handler;
    }
}
