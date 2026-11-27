<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\RelyingPartySettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function json_decode;

/**
 * REQ-RUNTIME-002, 2.6.0: ONE booted kernel (debug off, never reset: FrankenPHP worker mode with the kernel reset off)
 * serves ceremony options while the host's relying party setting changes between requests. Each request gets the RP id
 * saved at that moment, an invalid value disables passkeys for that request only, and the next valid value works again:
 * nothing is memoized across requests.
 */
final class WebauthnRelyingPartyWorkerKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnProviderTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false, 'environment' => 'worker']);
    }

    public function testEveryRequestOfOneKeptAliveKernelReadsTheCurrentRelyingParty(): void
    {
        self::bootKernel();
        $http     = self::getContainer()->get('http_kernel');
        $settings = self::getContainer()->get(RelyingPartySettings::class);
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(RelyingPartySettings::class, $settings);

        $settings->rpId    = 'example.com';
        $settings->origins = ['https://example.com'];
        self::assertSame(['rpId', 'example.com'], $this->rpId($http, 'example.com'));

        $settings->rpId    = 'example.org';
        $settings->origins = ['https://login.example.org'];
        self::assertSame(['rpId', 'example.org'], $this->rpId($http, 'login.example.org'));

        $settings->origins = ['https://example.com'];
        self::assertSame(['error', 'unavailable'], $this->rpId($http, 'example.org'));

        // Debug off and no allow_http_localhost: the configuration's http://localhost is refused too.
        $settings->rpId    = null;
        $settings->origins = null;
        self::assertSame(['error', 'unavailable'], $this->rpId($http, 'localhost', false));

        $settings->rpId    = 'example.com';
        $settings->origins = ['https://example.com'];
        self::assertSame(['rpId', 'example.com'], $this->rpId($http, 'example.com'));
    }

    /**
     * The RP id of fresh sign-in options, or the error.
     *
     * @return array{0: string, 1: mixed}
     */
    private function rpId(HttpKernelInterface $http, string $host, bool $https = true): array
    {
        $request  = Request::create(($https ? 'https://' : 'http://') . $host . '/login/webauthn/options', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $http->handle($request);
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        if ($response->getStatusCode() === Response::HTTP_SERVICE_UNAVAILABLE) {
            return ['error', $data['error'] ?? null];
        }
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return ['rpId', $data['rpId'] ?? null];
    }
}
