<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function parse_str;
use function parse_url;
use function sprintf;

use const PHP_URL_QUERY;

/**
 * AK-2 availability checkers and AK-3 social callback handlers through the real kernel.
 */
final class HostExtensionsKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return HostExtensionsTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new SocialLoginCredential())->setProvider('google')->setLabel('Google')->setEnabled(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder'));
        $em->flush();
        $em->clear();
    }

    public function testAvailableFeaturesKeepTheirLinksAndRoutes(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/magic-login"]'));
        self::assertCount(1, $crawler->filter('a[href$="/reset-password"]'));

        foreach (['/magic-login', '/reset-password', '/reset-password/complete'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testUnavailableMagicLoginHidesTheLinkAnd404s(): void
    {
        $this->checker('test.magic_checker')->answer = false;

        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/magic-login"]'));
        self::assertCount(1, $crawler->filter('a[href$="/reset-password"]'), 'The other feature has its own checker.');

        $this->client->request('GET', '/magic-login');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/magic-login', ['magic_login_request' => ['identifier' => 'ada@example.com']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnavailablePasswordResetHidesTheLinkAnd404sEveryRoute(): void
    {
        $this->checker('test.reset_checker')->answer = false;

        $crawler = $this->client->request('GET', '/login');
        self::assertCount(0, $crawler->filter('a[href$="/reset-password"]'));
        self::assertCount(1, $crawler->filter('a[href$="/magic-login"]'));

        foreach (['/reset-password', '/reset-password/complete', '/reset-password/reset/some-token'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            $this->client->request('POST', $path);
            self::assertResponseStatusCodeSame(404, 'POST ' . $path);
        }
    }

    public function testThrowingCheckerFailsClosedAndKeepsThePasswordSignIn(): void
    {
        $this->checker('test.magic_checker')->answer = null;
        $this->checker('test.reset_checker')->answer = null;

        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="login_form"]'));
        self::assertCount(0, $crawler->filter('a[href$="/magic-login"]'));
        self::assertCount(0, $crawler->filter('a[href$="/reset-password"]'));

        $this->client->request('GET', '/magic-login');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHandlerOwnsAVerifiedCallbackInsteadOfTheDefaultLogin(): void
    {
        $this->client->request('GET', '/test/pending');
        $state = $this->startAuthorization();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertResponseIsSuccessful();
        self::assertSame('handled|default|google|new.person@example.com|at-placeholder|social', $this->client->getResponse()->getContent());
        self::assertNull($this->tokens()->getToken(), 'The default sign-in did not run.');
        self::assertSame(0, $this->em()->getRepository(KernelUser::class)->count([]), 'The default linker created no account.');
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]), 'The default linker linked nothing.');
    }

    public function testStateIsVerifiedBeforeTheHandlerAndNoCodeIsExchanged(): void
    {
        $this->client->request('GET', '/test/pending');
        $this->startAuthorization();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => 'forged']);

        self::assertSame('failed|google|invalid_state', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->provider()->requests);
    }

    public function testAReplayedStateIsRefused(): void
    {
        $this->client->request('GET', '/test/pending');
        $state = $this->startAuthorization();
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertStringStartsWith('handled|', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/test/pending');
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertSame('failed|google|invalid_state', $this->client->getResponse()->getContent());
    }

    public function testProviderDenialBurnsTheState(): void
    {
        $this->client->request('GET', '/test/pending');
        $state = $this->startAuthorization();

        $this->client->request('GET', '/login/social/google/check', ['error' => 'access_denied', 'state' => $state]);
        self::assertSame('failed|google|provider_denied', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/test/pending');
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);
        self::assertSame('failed|google|invalid_state', $this->client->getResponse()->getContent());
    }

    public function testExchangeFailureBecomesAFailure(): void
    {
        $this->client->request('GET', '/test/pending');
        $state                               = $this->startAuthorization();
        $this->provider()->failTokenExchange = true;

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertSame('failed|google|failed', $this->client->getResponse()->getContent());
    }

    public function testHandlerExceptionBecomesAFailureWithoutSignIn(): void
    {
        $this->handler()->throwOnHandle = true;
        $this->client->request('GET', '/test/pending');
        $state = $this->startAuthorization();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        self::assertSame('failed|google|failed', $this->client->getResponse()->getContent());
        self::assertNull($this->tokens()->getToken());
    }

    public function testUnknownProviderIsAFailureWithoutAnyExchange(): void
    {
        $this->client->request('GET', '/test/pending');
        $this->client->request('GET', '/login/social/nope/check', ['code' => 'c', 'state' => 's']);

        self::assertSame('failed|nope|unavailable', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->provider()->requests);
    }

    public function testNullFromTheFailureHookKeepsTheDefaultAnswer(): void
    {
        $this->handler()->defaultOnFailure = true;
        $this->client->request('GET', '/test/pending');

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => 'forged']);

        self::assertResponseRedirects('/login');
    }

    public function testWithoutAPendingStepTheDefaultFlowRuns(): void
    {
        $state = $this->startAuthorization();

        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        // The default linker: no such account and create_user_if_missing is off, so back to the sign-in page.
        self::assertResponseRedirects('/login');
        self::assertSame(2, $this->provider()->requests);
    }

    private function startAuthorization(): string
    {
        $this->client->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null, sprintf('No state in the redirect of %s.', 'google'));

        return $query['state'];
    }

    private function checker(string $id): ToggleAvailabilityChecker
    {
        $checker = self::getContainer()->get($id);
        self::assertInstanceOf(ToggleAvailabilityChecker::class, $checker);

        return $checker;
    }

    private function handler(): PendingStepCallbackHandler
    {
        $handler = self::getContainer()->get(PendingStepCallbackHandler::class);
        self::assertInstanceOf(PendingStepCallbackHandler::class, $handler);

        return $handler;
    }

    private function provider(): SocialProviderResponses
    {
        $provider = self::getContainer()->get(SocialProviderResponses::class);
        self::assertInstanceOf(SocialProviderResponses::class, $provider);

        return $provider;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
