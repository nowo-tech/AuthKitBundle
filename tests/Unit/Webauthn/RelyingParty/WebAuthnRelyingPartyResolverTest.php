<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Webauthn\RelyingParty;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\ValueObject\WebAuthnRelyingPartyContext;
use Nowo\AuthKitBundle\Webauthn\RelyingParty\ConfiguredWebAuthnRelyingPartyProvider;
use Nowo\AuthKitBundle\Webauthn\RelyingParty\WebAuthnRelyingPartyResolver;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The default provider (the configuration, as before 2.6.0) and the resolver that validates every answer and fails
 * closed (2.6.0).
 */
final class WebAuthnRelyingPartyResolverTest extends TestCase
{
    public function testTheDefaultProviderAnswersTheConfiguration(): void
    {
        $provider = new ConfiguredWebAuthnRelyingPartyProvider();
        $request  = new WebAuthnRelyingPartyContext('default', 'login.example.com', 'https://login.example.com:8443');
        self::assertSame('login.example.com', $provider->rpId($request));
        self::assertSame(['https://login.example.com'], $provider->allowedOrigins($request));

        $configured = new WebAuthnRelyingPartyContext('default', 'login.example.com', 'https://login.example.com', 'example.com', ['https://Login.Example.com']);
        self::assertSame('example.com', $provider->rpId($configured));
        self::assertSame(['https://login.example.com'], $provider->allowedOrigins($configured));

        // A localhost RP id served on localhost: the request origin (development, any port).
        self::assertSame(['http://localhost:8000'], $provider->allowedOrigins(new WebAuthnRelyingPartyContext('default', 'localhost', 'http://localhost:8000')));
        self::assertSame(['https://localhost'], $provider->allowedOrigins(new WebAuthnRelyingPartyContext('default', 'app.localhost', 'http://app.localhost', 'localhost')));
        self::assertSame('localhost', $provider->rpId(new WebAuthnRelyingPartyContext('default', 'localhost', 'http://localhost', '')));
    }

    public function testTheContextCarriesTheProfileAndTheRequest(): void
    {
        $context = WebAuthnRelyingPartyResolver::context($this->profile(['rp_id' => 'example.com', 'allowed_origins' => ['https://example.com', 8443, ['x']]]), Request::create('https://login.example.com:8443/login'));
        self::assertSame(['default', 'login.example.com', 'https://login.example.com:8443', 'example.com', ['https://example.com', '8443', '']], [$context->profile, $context->requestHost, $context->requestOrigin, $context->configuredRpId, $context->configuredOrigins]);

        $bare = WebAuthnRelyingPartyResolver::context($this->profile(['rp_id' => '', 'allowed_origins' => 'nope']), Request::create('http://localhost/login'));
        self::assertNull($bare->configuredRpId);
        self::assertSame([], $bare->configuredOrigins);
    }

    public function testAValidAnswerIsNormalisedToLowerCase(): void
    {
        $party = (new WebAuthnRelyingPartyResolver($this->provider('Example.COM', ['HTTPS://LOGIN.example.com'])))->resolve($this->profile(), Request::create('https://example.com/'));
        self::assertSame('example.com', $party->rpId);
        self::assertSame(['https://login.example.com'], $party->origins);
    }

    public function testHttpLocalhostFollowsKernelDebugUnlessTheProfileDecides(): void
    {
        $provider = $this->provider('localhost', ['http://localhost:8000']);
        $request  = Request::create('http://localhost:8000/');
        self::assertSame('localhost', (new WebAuthnRelyingPartyResolver($provider, true))->resolve($this->profile(), $request)->rpId);
        self::assertSame('localhost', (new WebAuthnRelyingPartyResolver($provider, false))->resolve($this->profile(['allow_http_localhost' => true]), $request)->rpId);
        $this->assertRefused(new WebAuthnRelyingPartyResolver($provider, false), $this->profile(), 'http://localhost:8000');
        $this->assertRefused(new WebAuthnRelyingPartyResolver($provider, true), $this->profile(['allow_http_localhost' => false]), 'http://localhost:8000');
    }

    public function testAnInvalidAnswerFailsClosedAndIsLogged(): void
    {
        $logger   = $this->logger();
        $resolver = new WebAuthnRelyingPartyResolver($this->provider('example.com', ['https://example.org']), false, $logger);
        $this->assertRefused($resolver, $this->profile(), 'https://example.com', 'Invalid WebAuthn relying party: The origin "https://example.org" is not on the RP id "example.com" or a sub-domain of it.');
        self::assertSame([['error', 'Passkeys are disabled: the WebAuthn relying party is invalid.', 'example.com']], $logger->records);
    }

    public function testAFailingProviderFailsClosedAndIsLogged(): void
    {
        $logger   = $this->logger();
        $provider = $this->createStub(WebAuthnRelyingPartyProviderInterface::class);
        $provider->method('rpId')->willThrowException(new RuntimeException('settings store down'));
        $failure = $this->assertRefused(new WebAuthnRelyingPartyResolver($provider, false, $logger), $this->profile(), 'https://example.com', 'The WebAuthn relying party provider failed.');
        self::assertInstanceOf(RuntimeException::class, $failure->getPrevious());
        self::assertSame([['error', 'Passkeys are disabled: the WebAuthn relying party provider failed.', null]], $logger->records);
    }

    private function assertRefused(WebAuthnRelyingPartyResolver $resolver, ProfileSettings $profile, string $url, ?string $message = null): WebauthnFailure
    {
        try {
            $resolver->resolve($profile, Request::create($url . '/'));
        } catch (WebauthnFailure $failure) {
            self::assertSame(WebauthnFailure::RELYING_PARTY, $failure->reason);
            if ($message !== null) {
                self::assertSame($message, $failure->getMessage());
            }

            return $failure;
        }
        self::fail('The relying party was accepted.');
    }

    /**
     * @param list<string> $origins
     */
    private function provider(string $rpId, array $origins): WebAuthnRelyingPartyProviderInterface
    {
        $provider = $this->createStub(WebAuthnRelyingPartyProviderInterface::class);
        $provider->method('rpId')->willReturn($rpId);
        $provider->method('allowedOrigins')->willReturn($origins);

        return $provider;
    }

    /**
     * @param array<string, mixed> $webauthn
     */
    private function profile(array $webauthn = []): ProfileSettings
    {
        return ProfileRegistryFactory::single(InMemoryUser::class, ['webauthn' => ['mode' => 'enabled'] + $webauthn])->getByName('default');
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, string, mixed}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string, mixed}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context['rp_id'] ?? null];
            }
        };
    }
}
