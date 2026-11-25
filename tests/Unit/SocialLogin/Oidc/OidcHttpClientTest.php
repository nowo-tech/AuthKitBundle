<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcHttpClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

use function str_repeat;

final class OidcHttpClientTest extends TestCase
{
    public function testFetchesJsonPinnedToACheckedAddressWithoutRedirects(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('{"ok":true}');
        });
        $resolver          = new FakeResolver();
        $resolver->answers = ['idp.example.com' => ['93.184.215.14', '2606:4700:4700::1111']];

        $result = (new OidcHttpClient($client, new OidcOptions(timeout: 3.0, maxDuration: 5.0), $resolver))->requestJson('GET', 'https://idp.example.com/x', 'discovery', ['headers' => ['X-Test' => '1']]);

        self::assertSame(['status' => 200, 'data' => ['ok' => true]], $result);
        self::assertSame(0, $seen['max_redirects']);
        self::assertSame(3.0, $seen['timeout']);
        self::assertSame(5.0, $seen['max_duration']);
        self::assertSame(['idp.example.com' => '93.184.215.14'], $seen['resolve']);
        self::assertContains('Accept: application/json', $seen['headers']);
        self::assertContains('X-Test: 1', $seen['headers']);
    }

    public function testAPublicIpLiteralIsNotResolvedNorPinned(): void
    {
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('{}');
        });

        $result = (new OidcHttpClient($client, new OidcOptions(), new FakeResolver()))->requestJson('GET', 'https://93.184.215.14/x', 'jwks');
        self::assertSame([], $result['data']);
        self::assertArrayNotHasKey('resolve', $seen);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'plain http' => ['http://idp.example.com/x', ['93.184.215.14']];
        yield 'localhost' => ['https://localhost/x', ['127.0.0.1']];
        yield 'private ip literal' => ['https://10.0.0.1/x', []];
        yield 'cgnat ip literal' => ['https://100.64.1.1/x', []];
        yield 'v6 loopback literal' => ['https://[::1]/x', []];
        yield 'name resolving to metadata' => ['https://idp.example.com/x', ['169.254.169.254']];
        yield 'name with one private address' => ['https://idp.example.com/x', ['93.184.215.14', '10.0.0.5']];
        yield 'name resolving to v4-mapped loopback' => ['https://idp.example.com/x', ['::ffff:127.0.0.1']];
        yield 'name that does not resolve' => ['https://idp.example.com/x', []];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('refusedEndpoints')]
    public function testSsrfGuardRefusesBeforeAnyRequest(string $url, array $addresses): void
    {
        $client            = new MockHttpClient(static fn (): never => self::fail('No request may be sent.'));
        $resolver          = new FakeResolver();
        $resolver->default = $addresses;

        try {
            (new OidcHttpClient($client, new OidcOptions(), $resolver))->requestJson('GET', $url, 'jwks');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_ENDPOINT, $e->reason);
        }
    }

    public function testARedirectIsNeverFollowed(): void
    {
        $this->expectFailure(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://10.0.0.1/']]), OidcException::REASON_HTTP);
    }

    public function testAnAnnouncedOversizedResponseIsAbortedWhileDownloading(): void
    {
        $this->expectFailure(new MockResponse(str_repeat('a', 5000), ['response_headers' => ['Content-Length' => '5000']]), OidcException::REASON_RESPONSE_TOO_BIG, 4096);
    }

    public function testAStreamedOversizedResponseIsAborted(): void
    {
        $this->expectFailure(new MockResponse(['{"a":"', str_repeat('b', 5000), '"}']), OidcException::REASON_RESPONSE_TOO_BIG, 4096);
    }

    public function testTransportFailure(): void
    {
        $this->expectFailure(new MockResponse('', ['error' => 'connection refused']), OidcException::REASON_HTTP);
    }

    public function testNotJson(): void
    {
        $this->expectFailure(new MockResponse('<html>'), OidcException::REASON_HTTP);
    }

    public function testAJsonListIsNotAnObject(): void
    {
        $this->expectFailure(new MockResponse('[1,2]'), OidcException::REASON_HTTP);
    }

    public function testAJsonScalarIsNotAnObject(): void
    {
        $this->expectFailure(new MockResponse('"text"'), OidcException::REASON_HTTP);
    }

    public function testATransportExceptionWithoutGuardCauseIsWrapped(): void
    {
        $client = new MockHttpClient(static fn (): never => throw new TransportException('boom'));
        try {
            (new OidcHttpClient($client, new OidcOptions(), new FakeResolver()))->requestJson('GET', 'https://idp.example.com/x', 'token');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_HTTP, $e->reason);
            self::assertStringNotContainsString('boom', $e->getMessage());
        }
    }

    public function testABodyLargerThanTheCapWithoutProgressIsRefused(): void
    {
        // A transport that does not report progress: the size is still checked once downloaded.
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            unset($options['on_progress']);

            return new MockResponse(str_repeat('c', 5000));
        });
        $http = new OidcHttpClient(new class($client) implements HttpClientInterface {
            public function __construct(private readonly MockHttpClient $inner)
            {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                unset($options['on_progress']);

                return $this->inner->request($method, $url, $options);
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->inner->stream($responses, $timeout);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function withOptions(array $options): static
            {
                return $this;
            }
        }, new OidcOptions(maxResponseBytes: 4096), new FakeResolver());

        try {
            $http->requestJson('GET', 'https://idp.example.com/x', 'token');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_RESPONSE_TOO_BIG, $e->reason);
        }
    }

    private function expectFailure(MockResponse $response, string $reason, int $max = OidcOptions::DEFAULT_MAX_RESPONSE_BYTES): void
    {
        try {
            (new OidcHttpClient(new MockHttpClient($response), new OidcOptions(maxResponseBytes: $max), new FakeResolver()))->requestJson('GET', 'https://idp.example.com/x', 'discovery');
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
