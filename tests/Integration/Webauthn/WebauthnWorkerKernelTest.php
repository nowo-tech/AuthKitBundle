<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function intdiv;
use function memory_get_usage;
use function sprintf;
use function str_starts_with;

/**
 * REQ-RUNTIME-002 worker test of the passkeys (2.4.0): ONE booted kernel (debug off) serves every request through
 * `http_kernel`, never reset (FrankenPHP worker mode, kernel reset false). Alice and Bob register a passkey each, then
 * alternate for {@see self::ITERATIONS} rounds of a passwordless sign-in from a new browser (login page, options,
 * assertion) and a page reading the signed-in user, plus one round in three where Bob answers Alice's challenge with
 * his own passkey after she asked for it in her browser (refused, and nobody is signed in). Every sign-in is its own
 * identity and memory stays within {@see self::MEMORY_MARGIN} after the warm-up.
 */
final class WebauthnWorkerKernelTest extends KernelTestCase
{
    private const ITERATIONS = 300;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const USERS = ['alice@example.com' => 'alice-password-1', 'bob@example.com' => 'bob-password-22'];

    /** @var array<string, array<string, string>> browser => cookie name => value */
    private array $jars = [];

    protected static function getKernelClass(): string
    {
        return WebauthnWorkerTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false, 'environment' => 'worker']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $schema = new SchemaTool($em);
        $all    = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($all);
        $schema->createSchema($all);
        foreach (self::USERS as $email => $password) {
            $em->persist((new KernelUser())->setEmail($email)->setPassword($password));
        }
        $em->flush();
        $em->clear();
    }

    public function testTwoUsersSigningInWithPasskeysInOneKernelNeverSeeEachOtherAndMemoryStaysFlat(): void
    {
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $passkeys = [];
        foreach (self::USERS as $email => $password) {
            $passkeys[$email] = new SoftwareAuthenticator();
            $this->register($http, $email, $password, $passkeys[$email]);
        }

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $identity = $i % 2 === 0 ? 'alice@example.com' : 'bob@example.com';
            $round    = sprintf('Round %d (%s)', $i, $identity);
            $browser  = $identity . '#' . $i;
            // One client address per round: the per-IP limits are not what this test is about.
            $ip = sprintf('10.1.%d.%d', intdiv($i, 250), $i % 250 + 1);

            $assertion = $this->passkeySignIn($http, $browser, $passkeys[$identity], $ip, $round);
            self::assertTrue($assertion->isRedirect(), $round);
            self::assertStringEndsWith('/home', (string) $assertion->headers->get('Location'), $round);
            $home = (string) $this->send($http, $browser, Request::create('/mfa-home'))->getContent();
            self::assertTrue(str_starts_with($home, $identity . '|'), $round . ': the signed-in user is its own (' . $home . ').');

            if ($i % 3 === 0) {
                // Alice asks for options; Bob's passkey answers them in her browser: refused, still anonymous.
                $other   = $identity === 'alice@example.com' ? 'bob@example.com' : 'alice@example.com';
                $refused = $this->passkeySignIn($http, $browser . '#x', $passkeys[$other], $ip, $round, ['user_handle' => 'not-' . $other]);
                self::assertStringEndsWith('/login', (string) $refused->headers->get('Location'), $round . ': a mismatched passkey is refused.');
                self::assertSame(302, $this->send($http, $browser . '#x', Request::create('/mfa-home'))->getStatusCode(), $round . ': nobody is signed in.');
            }
            unset($this->jars[$browser], $this->jars[$browser . '#x']);

            if ($i === 49) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over the requests.');
    }

    private function register(HttpKernelInterface $http, string $email, string $password, SoftwareAuthenticator $passkey): void
    {
        $page  = $this->send($http, $email, Request::create('/login'));
        $csrf  = (new Crawler((string) $page->getContent()))->filter('input[name="login_form[_csrf_token]"]')->attr('value');
        $login = $this->send($http, $email, Request::create('/login', 'POST', ['login_form' => ['_username' => $email, '_password' => $password, '_csrf_token' => $csrf]]));
        self::assertSame(302, $login->getStatusCode());
        $account = new Crawler((string) $this->send($http, $email, Request::create('/account/webauthn'))->getContent());
        $token   = $account->filter('input[name="webauthn_register[_token]"]')->attr('value');
        $options = (string) $this->send($http, $email, Request::create('/account/webauthn/register/options'))->getContent();
        $created = $this->send($http, $email, Request::create('/account/webauthn/register', 'POST', ['webauthn_register' => ['label' => 'Key', 'credential' => $passkey->register($options), '_token' => $token]]));
        self::assertSame('/account/webauthn', $created->headers->get('Location'));
        unset($this->jars[$email]);
    }

    /**
     * @param array{user_handle?: string|null} $tweaks
     */
    private function passkeySignIn(HttpKernelInterface $http, string $browser, SoftwareAuthenticator $passkey, string $ip, string $round, array $tweaks = []): Response
    {
        $page = $this->send($http, $browser, Request::create('/login', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]));
        self::assertSame(200, $page->getStatusCode(), $round);
        $token   = (new Crawler((string) $page->getContent()))->filter('input[name="webauthn_login[_token]"]')->attr('value');
        $options = (string) $this->send($http, $browser, Request::create('/login/webauthn/options', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]))->getContent();

        return $this->send($http, $browser, Request::create('/login/webauthn/assert', 'POST', ['webauthn_login' => ['credential' => $passkey->assert($options, 'http://localhost', $tweaks), '_token' => $token]], [], [], ['REMOTE_ADDR' => $ip]));
    }

    private function send(HttpKernelInterface $http, string $browser, Request $request): Response
    {
        foreach ($this->jars[$browser] ?? [] as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = $http->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jars[$browser][$cookie->getName()]);
                continue;
            }
            $this->jars[$browser][$cookie->getName()] = (string) $cookie->getValue();
        }
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        return $response;
    }
}
