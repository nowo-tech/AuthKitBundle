<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
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
