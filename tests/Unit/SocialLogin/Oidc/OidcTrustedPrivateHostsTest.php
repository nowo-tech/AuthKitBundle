<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use ArrayObject;
use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcHttpClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\SocialLogin\Oidc\PublicIpChecker;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * 2.9.0: `oidc.trusted_private_hosts` (test environment only): an exactly named host may resolve to private or
 * loopback addresses; metadata and the other reserved ranges stay refused, IP literals are never exempt.
 */
final class OidcTrustedPrivateHostsTest extends TestCase
{
    /** @var ArrayObject<int, array{string, string, array<mixed>}> */
    private ArrayObject $logs;

    protected function setUp(): void
    {
        $this->logs = new ArrayObject();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function acceptedForATrustedHost(): iterable
    {
        yield 'compose network' => ['https://mock-idp.e2e.test/x', ['172.18.0.5']];
        yield 'loopback' => ['https://mock-idp.e2e.test/x', ['127.0.0.1']];
        yield 'v6 loopback' => ['https://mock-idp.e2e.test/x', ['::1']];
        yield 'v6 unique local' => ['https://mock-idp.e2e.test/x', ['fd12:3456::5']];
        yield 'v4-mapped private' => ['https://mock-idp.e2e.test/x', ['::ffff:10.0.0.5']];
        yield 'host compared case-insensitively' => ['https://MOCK-IDP.E2E.TEST/x', ['192.168.1.10']];
        yield 'mixed public and private' => ['https://mock-idp.e2e.test/x', ['93.184.215.14', '10.0.0.5']];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('acceptedForATrustedHost')]
    public function testATrustedHostMayResolveToAPrivateAddressAndTheExemptionIsLogged(string $url, array $addresses): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $requestUrl, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('{"issuer":"x"}');
        });

        $result = $this->http($client, $addresses)->requestJson('GET', $url, 'discovery');

        self::assertSame(['issuer' => 'x'], $result['data']);
        self::assertSame($addresses[0], $seen['resolve'][array_key_first($seen['resolve'])], 'Still pinned to the checked address.');
        self::assertCount(1, $this->logs);
        [$level, $message] = $this->logs->getArrayCopy()[0];
        self::assertSame('debug', $level);
        self::assertStringContainsString('oidc.trusted_private_hosts', $message);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function refusedEvenWhenListed(): iterable
    {
        yield 'metadata v4' => ['https://mock-idp.e2e.test/x', ['169.254.169.254']];
        yield 'metadata v4 among private' => ['https://mock-idp.e2e.test/x', ['10.0.0.5', '169.254.169.254']];
        yield 'link-local v4' => ['https://mock-idp.e2e.test/x', ['169.254.10.1']];
        yield 'metadata v4-mapped' => ['https://mock-idp.e2e.test/x', ['::ffff:169.254.169.254']];
        yield 'metadata v6' => ['https://mock-idp.e2e.test/x', ['fd00:ec2::254']];
        yield 'metadata v6 fd20:ce::254' => ['https://mock-idp.e2e.test/x', ['fd20:ce::254']];
        yield 'metadata v6 fd00:c1::a9fe:a9fe' => ['https://mock-idp.e2e.test/x', ['fd00:c1::a9fe:a9fe']];
        yield 'link-local v6' => ['https://mock-idp.e2e.test/x', ['fe80::1']];
        yield 'nat64 of metadata' => ['https://mock-idp.e2e.test/x', ['64:ff9b::a9fe:a9fe']];
        yield 'cgnat' => ['https://mock-idp.e2e.test/x', ['100.64.0.1']];
        yield 'unspecified' => ['https://mock-idp.e2e.test/x', ['0.0.0.0']];
        yield 'multicast' => ['https://mock-idp.e2e.test/x', ['224.0.0.1']];
        yield 'subdomain of a listed host' => ['https://evil.mock-idp.e2e.test/x', ['10.0.0.5']];
        yield 'listed host as a suffix' => ['https://xmock-idp.e2e.test/x', ['10.0.0.5']];
        yield 'private ip literal' => ['https://10.0.0.5/x', []];
        yield 'plain http' => ['http://mock-idp.e2e.test/x', ['10.0.0.5']];
    }

    /**
     * SECURITY: the exemption covers private / loopback addresses of an exactly listed host only.
     *
     * @param list<string> $addresses
     */
    #[DataProvider('refusedEvenWhenListed')]
    public function testRefusedEvenWithTheHostListed(string $url, array $addresses): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('No request may be sent.'));

        try {
            $this->http($client, $addresses)->requestJson('GET', $url, 'jwks');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_ENDPOINT, $e->reason);
        }
        self::assertCount(0, $this->logs);
    }

    public function testWithoutTheListAPrivateAddressIsRefused(): void
    {
        $resolver          = new FakeResolver();
        $resolver->default = ['172.18.0.5'];

        $this->expectException(OidcException::class);
        (new OidcHttpClient(new MockHttpClient(static fn (): never => self::fail('No request may be sent.')), new OidcOptions(), $resolver))->requestJson('GET', 'https://mock-idp.e2e.test/x', 'token');
    }

    public function testThePublicPathLogsNothing(): void
    {
        $this->http(new MockHttpClient(new MockResponse('{}')), ['93.184.215.14'])->requestJson('GET', 'https://mock-idp.e2e.test/x', 'userinfo');
        self::assertCount(0, $this->logs);
    }

    public function testOptionsRefuseWildcardsAndIpLiterals(): void
    {
        self::assertSame(['mock-idp.e2e.test'], (new OidcOptions(trustedPrivateHosts: ['Mock-IdP.E2E.test', 'mock-idp.e2e.test'], environment: 'test'))->trustedPrivateHosts);
        foreach (['*.e2e.test', '10.0.0.5', '::1', '10.0.0.0/8', 'idp:443', 1] as $entry) {
            try {
                new OidcOptions(trustedPrivateHosts: [$entry], environment: 'test'); // @phpstan-ignore argument.type (invalid entries on purpose)
                self::fail('refused');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('trusted_private_hosts', $e->getMessage());
            }
        }
    }

    /**
     * SECURITY (audit ZDO-AK-I3): a list that reached the options without the extension's check (a host writing the
     * internal parameter itself) is still refused outside kernel.environment "test".
     */
    public function testOptionsRefuseTheListOutsideTheTestEnvironment(): void
    {
        self::assertSame([], (new OidcOptions(environment: 'prod'))->trustedPrivateHosts);
        foreach (['prod', 'dev', null] as $environment) {
            try {
                new OidcOptions(trustedPrivateHosts: ['mock-idp.e2e.test'], environment: $environment);
                self::fail('refused');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('kernel.environment is "test"', $e->getMessage());
            }
        }
    }

    public function testCheckerClassification(): void
    {
        $checker = new PublicIpChecker();
        foreach (['10.1.2.3', '172.31.255.1', '192.168.0.1', '127.0.0.1', '::1', 'fd00::1', '::ffff:192.168.0.1'] as $ip) {
            self::assertTrue($checker->isTrustablePrivate($ip), $ip);
        }
        foreach (['169.254.169.254', '::ffff:169.254.169.254', 'fd00:ec2::254', 'fe80::1', '100.64.0.1', '8.8.8.8', 'not-an-ip', '2002:a9fe:a9fe::1'] as $ip) {
            self::assertFalse($checker->isTrustablePrivate($ip), $ip);
        }
        self::assertTrue($checker->isMetadata('169.254.0.1'));
        self::assertTrue($checker->isMetadata('fd00:ec2::254'));
        self::assertTrue($checker->isMetadata('fd20:ce::254'));
        self::assertTrue($checker->isMetadata('fd00:c1::a9fe:a9fe'));
        self::assertTrue($checker->isMetadata('FD20:00CE:0:0:0:0:0:0254'));
        self::assertFalse($checker->isTrustablePrivate('fd20:ce::254'));
        self::assertFalse($checker->isTrustablePrivate('fd00:c1::a9fe:a9fe'));
        self::assertTrue($checker->isMetadata('::ffff:169.254.169.254'));
        self::assertFalse($checker->isMetadata('fd00:ec2::253'));
        self::assertFalse($checker->isMetadata('10.0.0.1'));
        self::assertFalse($checker->isMetadata('nope'));
    }

    /**
     * @param list<string> $addresses
     */
    private function http(MockHttpClient $client, array $addresses): OidcHttpClient
    {
        $resolver          = new FakeResolver();
        $resolver->default = $addresses;
        $logs              = $this->logs;
        $logger            = new class($logs) extends AbstractLogger {
            /**
             * @param ArrayObject<int, array{string, string, array<mixed>}> $logs
             */
            public function __construct(private readonly ArrayObject $logs)
            {
            }

            /**
             * @param array<mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->logs->append([(string) $level, (string) $message, $context]);
            }
        };

        return new OidcHttpClient($client, new OidcOptions(trustedPrivateHosts: ['mock-idp.e2e.test'], environment: 'test'), $resolver, logger: $logger);
    }
}
