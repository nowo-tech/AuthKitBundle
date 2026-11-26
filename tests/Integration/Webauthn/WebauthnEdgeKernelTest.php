<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Port\Internal\UserLookupInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\RacingManagerRegistry;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Controller\WebauthnLoginController;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRenamedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnCeremonies;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\AuthKitBundle\Webauthn\WebauthnRemoval;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function array_map;
use function json_decode;
use function str_repeat;

/**
 * Edge paths of the passkey services (2.4.0): a session that changed user between options and registration, the
 * passkey limit reached by a parallel registration, a parallel use of the same challenge, a user who no longer exists,
 * remember-me sessions, a hand-built manager without locks, a rename racing another writer (2.5.0), a firewall without a programmatic sign-in.
 */
final class WebauthnEdgeKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function aRegistrationStartedForAnotherAccountIsRefused(): void
    {
        $request = $this->request();
        $options = $this->passkeys()->registrationOptions($this->user('member@example.com'), $request);
        try {
            $this->passkeys()->register($this->user('admin@example.com'), $request, (new SoftwareAuthenticator())->register($options), 'Key');
            self::fail('Another account\'s options are refused.');
        } catch (WebauthnFailure $failure) {
            self::assertSame(WebauthnFailure::INVALID, $failure->reason);
        }
        self::assertSame([], $this->passkeys()->passkeys($this->user('admin@example.com')));
    }

    #[Test]
    public function theLimitHoldsAgainstAParallelRegistration(): void
    {
        $member = $this->user('member@example.com');
        $first  = $this->request();
        $second = $this->request();
        $this->registerWith($member, 'A');
        $optionsA = $this->passkeys()->registrationOptions($member, $first);
        $optionsB = $this->passkeys()->registrationOptions($member, $second);
        $this->passkeys()->register($member, $second, (new SoftwareAuthenticator())->register($optionsB), 'B');
        $this->registerWith($member, 'C');
        try {
            $this->passkeys()->register($member, $first, (new SoftwareAuthenticator())->register($optionsA), 'D');
            self::fail('The fourth passkey is refused.');
        } catch (WebauthnFailure $failure) {
            self::assertSame(WebauthnFailure::LIMIT, $failure->reason);
        }
        self::assertCount(3, $this->passkeys()->passkeys($member));
    }

    #[Test]
    public function aChallengeClaimedByAParallelRequestIsRefused(): void
    {
        $request = $this->request();
        $profile = $this->profile();
        $options = $this->ceremonies()->requestOptions($profile, $request, 'login');
        $state   = $request->getSession()->get(WebauthnCeremonies::SESSION_KEY);
        self::assertIsArray($state);
        self::assertIsArray($state['default.login']);
        // The other request of the same session claimed it first (its session copy still had the challenge).
        $this->limiter()->consume('webauthn:challenge:' . $state['default.login']['challenge'], 1, 600);
        try {
            $this->ceremonies()->assertion($profile, $request, 'login', (new SoftwareAuthenticator())->assert($options));
            self::fail('Claimed already.');
        } catch (WebauthnFailure $failure) {
            self::assertSame(WebauthnFailure::CHALLENGE, $failure->reason);
        }
        foreach (['', str_repeat('x', WebauthnCeremonies::MAX_RESPONSE_BYTES + 1)] as $json) {
            try {
                $this->ceremonies()->verifyCreation($profile, $request, $json);
                self::fail('Empty or oversized.');
            } catch (WebauthnFailure $failure) {
                self::assertSame(WebauthnFailure::MALFORMED, $failure->reason);
            }
        }
    }

    #[Test]
    public function anAssertionIsNotARegistration(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($authenticator);
        $page    = $this->client->request('GET', '/account/webauthn');
        $options = $this->json('/account/webauthn/register/options');
        $decoded = json_decode($options, true);
        self::assertIsArray($decoded);
        $this->client->submit($page->filter('form[name="webauthn_register"]')->form(['webauthn_register[credential]' => $authenticator->assert('{"challenge":"' . $decoded['challenge'] . '","rpId":"localhost"}')]));
        self::assertResponseRedirects('/account/webauthn');
        self::assertSame([WebauthnFailure::MALFORMED], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
    }

    #[Test]
    public function aPasskeyOfAUserWhoIsGoneSignsNobodyIn(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($authenticator);
        $this->client->getCookieJar()->clear();
        $em   = $this->em();
        $user = $em->find(KernelUser::class, 'member@example.com');
        self::assertInstanceOf(KernelUser::class, $user);
        $em->remove($user);
        $em->flush();

        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects('/login');
        self::assertSame([WebauthnFailure::USER], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
    }

    #[Test]
    public function aRememberMeSessionSignsInAgainBeforeChangingPasskeys(): void
    {
        $this->signIn('member@example.com', 'member-password-123', true);
        $remember = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($remember);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        $this->client->request('GET', '/account/webauthn');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function aHandBuiltManagerWorksWithoutLocks(): void
    {
        $this->registerWith($this->user('member@example.com'), 'A');
        $id      = $this->passkeys()->passkeys($this->user('member@example.com'))[0]->publicId;
        $manager = new WebauthnManager(
            $this->service(ProfileRegistry::class),
            new WebauthnStore($this->doctrine()),
            $this->ceremonies(),
            new MfaOwnerResolver($this->doctrine()),
            $this->service(UserLookupInterface::class),
            $this->clock(),
            $this->limiter(),
        );
        self::assertTrue($manager->rename($this->user('member@example.com'), $id, 'B'));
        self::assertSame(WebauthnRemoval::LastFactor, $manager->remove($this->user('member@example.com'), $id, true));
        self::assertSame(WebauthnRemoval::Removed, $manager->remove($this->user('member@example.com'), $id));
        self::assertFalse($manager->rename($this->user('member@example.com'), $id, 'C'));
    }

    /**
     * @return iterable<string, array{string|null, bool, list<array{string, string}>, string|null, bool}>
     */
    public static function racingRenames(): iterable
    {
        // [the peer's write between the read and the UPDATE, the answer, the events (old, new), the stored label, in a
        // transaction]
        yield 'the peer renamed it: the rename replaces the peer\'s label' => ['Peer', true, [['Peer', 'Mine']], 'Mine', false];
        yield 'the same inside a transaction (locking re-read)' => ['Peer', true, [['Peer', 'Mine']], 'Mine', true];
        yield 'the peer already set the same label: nothing to write' => ['Mine', true, [], 'Mine', false];
        yield 'the peer removed it: unknown' => [null, false, [], null, false];
    }

    /**
     * 2.5.0 (REQ-RUNTIME-003): a write outside the lock between the read and the conditional UPDATE; the event reports
     * the label the rename really replaced. The real-database variants (REPEATABLE READ transaction, audit ZCH-M1) are
     * in tests/Database/WebauthnDatabaseTest.php.
     *
     * @param list<array{string, string}> $events
     */
    #[Test]
    #[DataProvider('racingRenames')]
    public function aRenameRacingAnotherWriterReportsTheLabelItReplaced(?string $peerLabel, bool $answer, array $events, ?string $stored, bool $inTransaction): void
    {
        $id      = $this->racedPasskey();
        $manager = $this->racingManager(static function (Connection $connection, string $table) use ($id, $peerLabel): void {
            $peerLabel === null
                ? $connection->executeStatement('DELETE FROM ' . $table . ' WHERE public_id = ?', [$id])
                : $connection->executeStatement('UPDATE ' . $table . ' SET label = ? WHERE public_id = ?', [$peerLabel, $id]);
        });

        $connection = $this->racingEm()->getConnection();
        if ($inTransaction) {
            $connection->beginTransaction();
        }
        self::assertSame($answer, $manager->rename($this->user('member@example.com'), $id, 'Mine'));
        if ($inTransaction) {
            $connection->commit();
        }
        self::assertSame($events, array_map(static fn (WebauthnCredentialRenamedEvent $e): array => [$e->oldLabel, $e->newLabel], $this->eventsOf(WebauthnCredentialRenamedEvent::class)));
        $passkeys = $this->passkeys()->passkeys($this->user('member@example.com'));
        self::assertSame($stored, $passkeys[0]->label ?? null);
    }

    /**
     * 2.5.0 (audit ZCH-M1): a label that changes under every attempt ends with a conflict after
     * {@see WebauthnManager::RENAME_ATTEMPTS} attempts (409 CONFLICT_001 on the page), not with a loop under the owner
     * lock; nothing is renamed by the rename and no event is dispatched.
     */
    #[Test]
    public function aRenameGivesUpAfterBoundedAttempts(): void
    {
        $id      = $this->racedPasskey();
        $writes  = 0;
        $manager = $this->racingManager(static function (Connection $connection, string $table) use ($id, &$writes): void {
            $connection->executeStatement('UPDATE ' . $table . ' SET label = ? WHERE public_id = ?', ['Peer ' . ++$writes, $id]);
        }, 4);

        try {
            $manager->rename($this->user('member@example.com'), $id, 'Mine');
            self::fail('The rename gives up.');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertSame(WebauthnManager::RENAME_ATTEMPTS, $writes);
        self::assertSame([], $this->eventsOf(WebauthnCredentialRenamedEvent::class));
        self::assertSame('Peer 3', $this->passkeys()->passkeys($this->user('member@example.com'))[0]->label);
    }

    #[Test]
    public function aFirewallWithoutAProgrammaticSignInIsRefusedBeforeTheChallengeIsUsed(): void
    {
        $request = $this->request('POST');
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $stack = $this->service(RequestStack::class);
        $stack->push($request);
        $token = $this->service(CsrfTokenManagerInterface::class)->getToken('nowo_auth_kit_webauthn_login.default')->getValue();
        $request->request->set('webauthn_login', ['credential' => '{}', '_token' => $token]);
        $controller = new WebauthnLoginController(
            $this->passkeys(),
            $this->service(FormFactoryInterface::class),
            $this->service(TokenStorageInterface::class),
            $this->service(Security::class),
            new ProgrammaticLoginAuthenticator(['other' => new ServiceLocator([])]),
            $this->service(RequestProfileResolver::class),
            $this->service(AuthKitUrlGenerator::class),
            $this->service(AuthKitFlashMessenger::class),
        );
        $response = $controller->assert($request);
        $stack->pop();
        self::assertSame('/login', $response->headers->get('Location'));
        self::assertSame([], $this->eventsOf(WebauthnAuthenticationFailedEvent::class), 'Nothing was attempted.');
    }

    private function racedPasskey(): string
    {
        $this->registerWith($this->user('member@example.com'), 'A');

        return $this->passkeys()->passkeys($this->user('member@example.com'))[0]->publicId;
    }

    private function racingEm(): EntityManagerInterface
    {
        $em = $this->doctrine()->getManagerForClass(WebauthnCredential::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * A hand-built manager (no lock) whose store lets `$peer` write before its first UPDATE (and every later one with
     * `$every` = 4).
     *
     * @param Closure(Connection, string): void $peer
     */
    private function racingManager(Closure $peer, int $every = 0): WebauthnManager
    {
        $em         = $this->racingEm();
        $table      = $em->getClassMetadata(WebauthnCredential::class)->getTableName();
        $dispatcher = $this->service('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new WebauthnManager(
            $this->service(ProfileRegistry::class),
            new WebauthnStore(new RacingManagerRegistry($em, 3, static fn (EntityManagerInterface $em) => $peer($em->getConnection(), $table), $every)),
            $this->ceremonies(),
            new MfaOwnerResolver($this->doctrine()),
            $this->service(UserLookupInterface::class),
            $this->clock(),
            $this->limiter(),
            null,
            $dispatcher,
        );
    }

    private function registerWith(KernelUser $user, string $label): void
    {
        $request = $this->request();
        $this->passkeys()->register($user, $request, (new SoftwareAuthenticator())->register($this->passkeys()->registrationOptions($user, $request)), $label);
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('http://localhost/account/webauthn', $method);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function profile(): ProfileSettings
    {
        return $this->service(ProfileRegistry::class)->getByName('default');
    }

    private function ceremonies(): WebauthnCeremonies
    {
        return $this->service(WebauthnCeremonies::class);
    }

    private function limiter(): AuthKitAttemptLimiter
    {
        return $this->service(AuthKitAttemptLimiter::class);
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = $this->service('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    private function service(string $id): object
    {
        return self::getContainer()->get($id);
    }
}
