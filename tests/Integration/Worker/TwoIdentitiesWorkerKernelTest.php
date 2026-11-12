<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\CapturingPasswordResetNotifier;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
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

/**
 * REQ-RUNTIME-002 worker test (1.46.0): ONE booted kernel serves every request through the `http_kernel` service, so
 * `kernel.reset` / the services resetter never runs (FrankenPHP worker mode, kernel reset false). Two identities (alice
 * and bob, each with its own browser session) alternate for {@see self::ITERATIONS} rounds over AuthKit's main paths:
 * the sign-in page and form login (the first two rounds of every 50, from a new browser), a page reading the signed-in
 * user, and the password reset request (page + submit) from a signed-out browser. Every response belongs to its own identity, the reset credential is issued
 * for the identity that asked for it, and memory stays within {@see self::MEMORY_MARGIN} after the warm-up.
 */
final class TwoIdentitiesWorkerKernelTest extends KernelTestCase
{
    private const ITERATIONS = 500;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const USERS = ['alice@example.com' => 'alice-password-1', 'bob@example.com' => 'bob-password-22'];

    /** @var array<string, array<string, string>> identity => cookie name => value */
    private array $jars = [];

    protected static function getKernelClass(): string
    {
        return TwoIdentitiesWorkerTestKernel::class;
    }

    /**
     * A production-like kernel (debug off): in debug mode the profiler and the Doctrine query logger keep every request
     * until kernel.reset, which this test never runs.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em     = $this->entityManager();
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

    public function testTwoIdentitiesAlternatingInOneKernelNeverSeeEachOtherAndMemoryStaysFlat(): void
    {
        $http     = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $notifier = self::getContainer()->get(CapturingPasswordResetNotifier::class);
        self::assertInstanceOf(CapturingPasswordResetNotifier::class, $notifier);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $identity = $i % 2 === 0 ? 'alice@example.com' : 'bob@example.com';
            $round    = sprintf('Round %d (%s)', $i, $identity);

            if ($i % 50 < 2) {
                // Sign out (a new browser), then the sign-in page and the form login.
                $this->jars[$identity] = [];
                $page = $this->send($http, $identity, Request::create('/login'));
                self::assertSame(200, $page->getStatusCode(), $round);
                $csrf  = (new Crawler((string) $page->getContent()))->filter('input[name="login_form[_csrf_token]"]')->attr('value');
                $login = $this->send($http, $identity, Request::create('/login', 'POST', ['login_form' => ['_username' => $identity, '_password' => self::USERS[$identity], '_csrf_token' => $csrf]]));
                self::assertSame(302, $login->getStatusCode(), $round);
                self::assertStringEndsWith('/home', (string) $login->headers->get('Location'), $round);
            }

            self::assertSame($identity, $this->send($http, $identity, Request::create('/whoami'))->getContent(), $round . ': the signed-in user is its own.');

            // The reset request runs in a signed-out browser of the same person: a token left by the previous request
            // (another identity) would redirect it away as signed in.
            $anonymous              = $identity . '#signed-out';
            $this->jars[$anonymous] = [];
            $page                   = $this->send($http, $anonymous, Request::create('/reset-password'));
            self::assertSame(200, $page->getStatusCode(), $round . ': a signed-out browser sees no other identity.');
            $token  = (new Crawler((string) $page->getContent()))->filter('input[name="reset_password_request_form[_token]"]')->attr('value');
            $notifier->last = null;
            // One client address per round: the per-IP request limit (5 per 15 minutes) is not what this test is about.
            $submit = $this->send($http, $anonymous, Request::create('/reset-password', 'POST', ['reset_password_request_form' => ['identifier' => $identity, '_token' => $token]], [], [], ['REMOTE_ADDR' => sprintf('10.0.%d.%d', intdiv($i, 250), $i % 250 + 1)]));
            self::assertSame(302, $submit->getStatusCode(), $round);
            $user = $notifier->last?->user;
            self::assertInstanceOf(KernelUser::class, $user, $round);
            self::assertSame($identity, $user->getUserIdentifier(), $round . ': the reset credential is issued for its own identity.');

            if ($i === 49) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory stays flat over the requests.');
    }

    private function send(HttpKernelInterface $http, string $identity, Request $request): Response
    {
        foreach ($this->jars[$identity] ?? [] as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = $http->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jars[$identity][$cookie->getName()]);
                continue;
            }
            $this->jars[$identity][$cookie->getName()] = (string) $cookie->getValue();
        }
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        return $response;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
