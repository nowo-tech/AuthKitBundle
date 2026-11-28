<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Saml\SamlFailure;
use Nowo\AuthKitBundle\Saml\SamlSpKeyGenerator;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc\RecordingTestHandler;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlKeys;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlProviders;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlResponseBuilder;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\WebauthnSqlScript;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function base64_decode;
use function gzinflate;
use function parse_str;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function substr_count;

use const PHP_URL_QUERY;

/**
 * 2.10.0 SAML 2.0 SP sign-in through the real kernel: signed AuthnRequest, relayed ACS POST, just-in-time creation,
 * linking rules, replay and unsolicited refusals, the test sign-in, a host callback step and the SP metadata.
 */
final class SamlKernelTest extends WebTestCase
{
    private const ACS    = SamlTestKernel::ORIGIN . '/login/saml/acme/acs';
    private const ENTITY = SamlTestKernel::ORIGIN . '/saml/acme/metadata';

    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return SamlTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $pool = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->persist(SamlProviders::acme()->setEmailVerified(true));
        $em->persist(SamlProviders::acme('stepped')->setEmailVerified(true));
        $em->flush();
        $em->clear();
        $this->handler()->handled  = [];
        $this->handler()->failures = [];
        $this->step()->handled     = [];
        $this->step()->failures    = [];
        $this->step()->throw       = null;
        $this->failure()->throw    = null;
        $this->listener()->rejected = [];
    }

    public function testTheStartSendsASignedAuthnRequestAndTheLoginHint(): void
    {
        [$requestId, $relayState, $query, $xml] = $this->start('/login/saml/acme?login_hint=grace%40example.com');

        self::assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.-]{20,}$/', $requestId);
        self::assertSame(32, strlen($relayState));
        self::assertSame('grace@example.com', $query['login_hint']);
        self::assertSame('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $query['SigAlg']);
        self::assertNotSame('', $query['Signature']);
        self::assertStringContainsString('AssertionConsumerServiceURL="' . self::ACS . '"', $xml);
        self::assertStringContainsString('<saml:Issuer>' . self::ENTITY . '</saml:Issuer>', $xml);
        self::assertStringContainsString('Destination="https://idp.example.com/sso"', $xml);
    }

    public function testTheIdpPostIsRelayedWithoutTheSession(): void
    {
        $this->client->request('POST', '/login/saml/acme/acs', ['SAMLResponse' => 'PHJlc3BvbnNlLz4=', 'RelayState' => 'relay-1', 'evil' => 'x']);

        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="SAMLResponse"', $page);
        self::assertStringContainsString('value="relay-1"', $page);
        self::assertStringContainsString('name="' . SocialFormPostRelay::MARKER . '"', $page);
        self::assertStringNotContainsString('evil', $page);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $this->listener()->rejected);
    }

    public function testAFirstSignInCreatesTheAccountAndLinksTheSubject(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $builder                  = $this->builder($requestId);
        $builder->attributes['mail'] = ['grace@example.com'];
        $this->acs('acme', $builder->build(), $relayState);

        self::assertResponseRedirects('/home');
        self::assertSame('grace@example.com', $this->tokens()->getToken()?->getUserIdentifier());
        $link = $this->em()->getRepository(SocialLoginAccount::class)->findOneBy(['provider' => 'saml:acme']);
        self::assertInstanceOf(SocialLoginAccount::class, $link);
        self::assertSame('subject-1', $link->getProviderUserId());
        self::assertSame(SamlResponseBuilder::IDP_ENTITY_ID, $link->getIssuer());
        self::assertSame(['email' => 'grace@example.com', 'name' => 'Ada Lovelace', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'groups' => ['staff', 'admins']], $link->getRawProfile());
        self::assertNull($link->getAccessToken() !== '' ? $link->getAccessToken() : null);

        // The next sign-in (another browser) is found by the subject, whatever the e-mail now says.
        $this->client->getCookieJar()->clear();
        $this->tokens()->setToken(null);
        [$requestId, $relayState]    = $this->start('/login/saml/acme');
        $builder                     = $this->builder($requestId);
        $builder->attributes['mail'] = ['grace.hopper@example.com'];
        $this->acs('acme', $builder->build(), $relayState);
        self::assertResponseRedirects('/home');
        self::assertSame('grace@example.com', $this->tokens()->getToken()?->getUserIdentifier());
    }

    public function testAnEncryptedAssertionSignsIn(): void
    {
        [$requestId, $relayState]       = $this->start('/login/saml/acme');
        $builder                        = $this->builder($requestId);
        $builder->encryptForCertificate = SamlKeys::pair('sp')['cert'];
        $builder->attributes['mail']    = ['grace@example.com'];
        $this->acs('acme', $builder->build(), $relayState);

        self::assertResponseRedirects('/home');
        self::assertSame('grace@example.com', $this->tokens()->getToken()?->getUserIdentifier());
    }

    public function testAnEmailTheIdpIsNotAuthoritativeForNeverCreatesNorLinks(): void
    {
        $this->row('acme')->setEmailVerified(false);
        $this->em()->flush();
        foreach (['grace@example.com', 'ada@example.com'] as $email) {
            [$requestId, $relayState]    = $this->start('/login/saml/acme');
            $builder                     = $this->builder($requestId);
            $builder->attributes['mail'] = [$email];
            $this->acs('acme', $builder->build(), $relayState);

            self::assertResponseRedirects('/login');
            self::assertNull($this->tokens()->getToken(), $email);
        }
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(KernelUser::class)->count([]));
    }

    public function testTheRowLinkPolicyDenyKeepsTheLocalAccountUntouched(): void
    {
        $this->row('acme')->setLinkExistingAccount('deny');
        $this->em()->flush();
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $this->acs('acme', $this->builder($requestId)->build(), $relayState);

        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        $this->em()->clear();
        self::assertSame('old-password-123', $this->em()->getRepository(KernelUser::class)->findOneBy(['email' => 'ada@example.com'])?->getPassword());
    }

    public function testAResponseIsUsedOnceAndAnUnsolicitedOneIsRefused(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $response                 = $this->builder($requestId)->build();
        $this->acs('acme', $response, $relayState);
        self::assertResponseRedirects('/home');

        // Replayed in the same browser.
        $this->acs('acme', $response, $relayState);
        self::assertResponseRedirects('/login');
        self::assertSame(SamlFailure::UNSOLICITED, $this->listener()->rejected[0]->reason ?? null);
        self::assertSame('acme', $this->listener()->rejected[0]->provider);

        // Replayed from another browser.
        $this->client->getCookieJar()->clear();
        $this->tokens()->setToken(null);
        $this->acs('acme', $response, $relayState);
        self::assertResponseRedirects('/login');
        self::assertNull($this->tokens()->getToken());
        self::assertSame(SamlFailure::UNSOLICITED, $this->listener()->rejected[1]->reason ?? null);
    }

    public function testACraftedRelayStateLeavesThePendingSignInAlone(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $response                 = $this->builder($requestId)->build();
        $this->acs('acme', $response, 'crafted');
        self::assertResponseRedirects('/login');
        self::assertSame(SamlFailure::INVALID_STATE, $this->listener()->rejected[0]->reason ?? null);

        $this->acs('acme', $response, $relayState);
        self::assertResponseRedirects('/home');
    }

    public function testAnUnsignedResponseBurnsThePendingSignIn(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $builder                  = $this->builder($requestId);
        $builder->sign            = 'none';
        $this->acs('acme', $builder->build(), $relayState);

        self::assertResponseRedirects('/login');
        self::assertSame(SamlFailure::SIGNATURE, $this->listener()->rejected[0]->reason ?? null);
        $this->acs('acme', $this->builder($requestId)->build(), $relayState);
        self::assertSame(SamlFailure::UNSOLICITED, $this->listener()->rejected[1]->reason ?? null);
        self::assertNull($this->tokens()->getToken());
    }

    public function testADisabledOrUnknownProviderNeitherStartsNorSignsIn(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/acme');
        $this->row('acme')->setEnabled(false);
        $this->em()->flush();
        $this->acs('acme', $this->builder($requestId)->build(), $relayState);
        self::assertResponseRedirects('/login');
        self::assertSame(SamlFailure::UNAVAILABLE, $this->listener()->rejected[0]->reason ?? null);

        foreach (['/login/saml/acme', '/login/saml/unknown'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseRedirects('/login');
        }
        $this->client->request('POST', '/login/saml/unknown/acs', ['SAMLResponse' => 'x', 'RelayState' => 'y', SocialFormPostRelay::MARKER => '1']);
        self::assertResponseRedirects('/login');
    }

    public function testAnIncompleteRowDoesNotStart(): void
    {
        $em = $this->em();
        $em->persist((new SamlProvider())->setProvider('draft')->setLabel('Draft')->setEnabled(true));
        $em->flush();
        $this->client->request('GET', '/login/saml/draft');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/saml/draft/metadata');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStartsAreRateLimitedPerClient(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('GET', '/login/saml/acme');
            self::assertResponseStatusCodeSame(302);
            self::assertTrue(str_starts_with((string) $this->client->getResponse()->headers->get('Location'), 'https://idp.example.com/sso?'));
        }
        $this->client->request('GET', '/login/saml/acme');
        self::assertResponseRedirects('/login');
    }

    public function testATestSignInReachesOnlyTheTestHandler(): void
    {
        $this->row('acme')->setEnabled(false);
        $this->em()->flush();
        [$requestId, $relayState] = $this->start('/admin/saml-test/acme?login_hint=ada%40example.com');
        $this->acs('acme', $this->builder($requestId)->build(), $relayState);

        self::assertResponseRedirects('/admin/sso-test-result');
        self::assertNull($this->tokens()->getToken());
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        self::assertCount(1, $this->handler()->handled);
        $context = $this->handler()->handled[0];
        self::assertSame('saml:acme', $context->provider);
        self::assertSame('subject-1', $context->socialProfile->samlAssertion['name_id'] ?? null);
        self::assertSame(['staff', 'admins'], $context->socialProfile->samlAssertion['groups'] ?? null);
        self::assertSame('ada@example.com', $context->verifiedEmail());

        // A failed test reaches the test handler's failure answer.
        [$requestId, $relayState] = $this->start('/admin/saml-test/acme');
        $builder                  = $this->builder($requestId);
        $builder->audience        = 'https://other-sp.example.com';
        $this->acs('acme', $builder->build(), $relayState);
        self::assertResponseRedirects('/admin/sso-test-failed');
        self::assertSame([SocialCallbackFailure::Failed], $this->handler()->failures);

        $this->client->request('GET', '/admin/saml-test/unknown');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('unknown_provider', $this->client->getResponse()->getContent());
        $this->em()->persist((new SamlProvider())->setProvider('draft')->setLabel('Draft'));
        $this->em()->flush();
        $this->client->request('GET', '/admin/saml-test/draft');
        self::assertSame('provider', $this->client->getResponse()->getContent());
    }

    public function testAHostStepOwnsItsProvider(): void
    {
        [$requestId, $relayState] = $this->start('/login/saml/stepped');
        $this->acs('stepped', $this->builder($requestId, 'stepped')->build(), $relayState);
        self::assertResponseRedirects('/host/linking');
        self::assertCount(1, $this->step()->handled);
        self::assertSame('saml:stepped', $this->step()->handled[0]->provider);
        self::assertNull($this->tokens()->getToken());

        [$requestId, $relayState] = $this->start('/login/saml/stepped');
        $builder                  = $this->builder($requestId, 'stepped');
        $builder->sign            = 'none';
        $this->acs('stepped', $builder->build(), $relayState);
        self::assertResponseRedirects('/host/saml-failed');

        $this->step()->throw      = new RuntimeException('Host step failure.');
        [$requestId, $relayState] = $this->start('/login/saml/stepped');
        $this->acs('stepped', $this->builder($requestId, 'stepped')->build(), $relayState);
        self::assertResponseRedirects('/host/saml-failed');
        self::assertSame([SocialCallbackFailure::Failed, SocialCallbackFailure::Failed], $this->step()->failures);
    }

    public function testALockConflictWhileLinkingAnswers409AndKeepsNothing(): void
    {
        $this->failure()->throw      = new TransientStorageFailure(2);
        [$requestId, $relayState]    = $this->start('/login/saml/acme');
        $builder                     = $this->builder($requestId);
        $builder->attributes['mail'] = ['grace@example.com'];
        $this->acs('acme', $builder->build(), $relayState);

        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertTrue($this->em()->isOpen(), 'The host entity manager is never closed.');
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        self::assertNull($this->em()->getRepository(KernelUser::class)->findOneBy(['email' => 'grace@example.com']));
    }

    public function testAFailureInsideTheSignInKeepsNothing(): void
    {
        $this->failure()->throw      = new RuntimeException('A host listener failed.');
        [$requestId, $relayState]    = $this->start('/login/saml/acme');
        $builder                     = $this->builder($requestId);
        $builder->attributes['mail'] = ['grace@example.com'];
        $this->acs('acme', $builder->build(), $relayState);

        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
        self::assertNull($this->em()->getRepository(KernelUser::class)->findOneBy(['email' => 'grace@example.com']));
    }

    public function testALockConflictInAHostStepAnswers409(): void
    {
        $this->step()->throw      = new TransientStorageFailure(1);
        [$requestId, $relayState] = $this->start('/login/saml/stepped');
        $this->acs('stepped', $this->builder($requestId, 'stepped')->build(), $relayState);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('CONFLICT_001', $this->client->getResponse()->headers->get('X-Conflict-Code'));
    }

    public function testTheMetadataListsTheSpAndBothCertificatesDuringARotation(): void
    {
        $this->client->request('GET', '/saml/acme/metadata');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('application/samlmetadata+xml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('entityID="' . self::ENTITY . '"', $xml);
        self::assertStringContainsString('Location="' . self::ACS . '"', $xml);
        self::assertStringContainsString('AuthnRequestsSigned="true"', $xml);
        self::assertStringContainsString('WantAssertionsSigned="true"', $xml);
        self::assertStringNotContainsString('PRIVATE KEY', $xml);
        self::assertSame(2, substr_count($xml, '<ds:X509Certificate>'));

        $generator = self::getContainer()->get('test.saml_key_generator');
        self::assertInstanceOf(SamlSpKeyGenerator::class, $generator);
        $next = $generator->generate('sp.example.com', 30);
        $this->row('acme')->setNextSpKeyPair($next->privateKeyPem, $next->certificatePem);
        $this->em()->flush();
        $this->client->request('GET', '/saml/acme/metadata');
        self::assertSame(4, substr_count((string) $this->client->getResponse()->getContent(), '<ds:X509Certificate>'));

        $this->client->request('GET', '/saml/unknown/metadata');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSpPrivateKeyIsStoredEncrypted(): void
    {
        $raw = $this->em()->getConnection()->fetchAssociative('SELECT sp_private_key, sp_certificate, idp_certificate FROM auth_kit_saml_provider WHERE provider = ?', ['acme']);
        self::assertIsArray($raw);
        self::assertStringNotContainsString('PRIVATE KEY', (string) $raw['sp_private_key']);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', (string) $raw['sp_certificate']);
        self::assertStringContainsString('BEGIN CERTIFICATE', (string) $raw['idp_certificate']);
        self::assertStringContainsString('BEGIN PRIVATE KEY', (string) $this->row('acme')->getSpPrivateKey());
    }

    public function testTheShippedScriptsCreateAndDropTheMappedTable(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DROP TABLE ' . SamlProvider::TABLE);
        self::assertNotSame('', WebauthnSqlScript::differences($this->em(), 'saml'));
        WebauthnSqlScript::run($connection, 'up', 'saml');
        self::assertSame('', WebauthnSqlScript::differences($this->em(), 'saml'));
        $this->em()->persist(SamlProviders::acme());
        $this->em()->flush();

        WebauthnSqlScript::run($connection, 'down', 'saml');
        self::assertFalse($connection->createSchemaManager()->tablesExist([SamlProvider::TABLE]));
        foreach (['mysql', 'mariadb', 'postgresql', 'sqlite'] as $platform) {
            self::assertNotSame([], WebauthnSqlScript::statements($platform, 'up', 'saml'));
            self::assertSame(['DROP TABLE auth_kit_saml_provider'], WebauthnSqlScript::statements($platform, 'down', 'saml'));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>, 3: string} request id, RelayState, query, AuthnRequest XML
     */
    private function start(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertTrue(str_starts_with($location, 'https://idp.example.com/sso?'), $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $xml = (string) gzinflate((string) base64_decode($query['SAMLRequest'], true));
        self::assertSame(1, preg_match('/ ID="([^"]+)"/', $xml, $match));

        return [$match[1], $query['RelayState'], $query, $xml];
    }

    private function acs(string $provider, string $samlResponse, string $relayState): void
    {
        $this->client->request('POST', '/login/saml/' . $provider . '/acs', ['SAMLResponse' => $samlResponse, 'RelayState' => $relayState, SocialFormPostRelay::MARKER => '1']);
    }

    private function builder(string $requestId, string $provider = 'acme'): SamlResponseBuilder
    {
        return new SamlResponseBuilder($requestId, SamlTestKernel::ORIGIN . '/login/saml/' . $provider . '/acs', SamlTestKernel::ORIGIN . '/saml/' . $provider . '/metadata');
    }

    private function row(string $provider): SamlProvider
    {
        $row = $this->em()->getRepository(SamlProvider::class)->findOneBy(['provider' => $provider]);
        self::assertInstanceOf(SamlProvider::class, $row);

        return $row;
    }

    private function handler(): RecordingTestHandler
    {
        $handler = self::getContainer()->get(RecordingTestHandler::class);
        self::assertInstanceOf(RecordingTestHandler::class, $handler);

        return $handler;
    }

    private function step(): RecordingSamlStep
    {
        $step = self::getContainer()->get(RecordingSamlStep::class);
        self::assertInstanceOf(RecordingSamlStep::class, $step);

        return $step;
    }

    private function failure(): SignInFailureListener
    {
        $listener = self::getContainer()->get(SignInFailureListener::class);
        self::assertInstanceOf(SignInFailureListener::class, $listener);

        return $listener;
    }

    private function listener(): RecordingSamlListener
    {
        $listener = self::getContainer()->get(RecordingSamlListener::class);
        self::assertInstanceOf(RecordingSamlListener::class, $listener);

        return $listener;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
