<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;
use JsonException;
use Nowo\AuthKitBundle\SocialLogin\OAuthEndpointUrlValidator;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_is_list;
use function filter_var;
use function is_array;
use function json_decode;
use function parse_url;
use function sprintf;
use function strlen;
use function trim;

use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;

/**
 * Every outbound OpenID Connect call (discovery, JWKS, token, userinfo) goes through here (2.3.0, SSRF guard):
 *
 * - HTTPS only, no loopback / private / reserved address, whether written as an IP or reached through a host name
 *   (every resolved address is checked, and the connection is pinned to a checked one: no DNS rebinding);
 * - no redirects at all (a redirect cannot lead to an internal address, nor move the token request elsewhere);
 * - idle and total deadlines (`nowo_auth_kit.http_client`), and a response size cap (`oidc.max_response_bytes`)
 *   enforced while downloading, not after;
 * - JSON objects only.
 *
 * Stateless: everything comes from the arguments (REQ-RUNTIME-002).
 */
final class OidcHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OidcOptions $options,
        private readonly HostResolverInterface $resolver = new DnsHostResolver(),
        private readonly OAuthEndpointUrlValidator $urlValidator = new OAuthEndpointUrlValidator(),
        private readonly PublicIpChecker $ipChecker = new PublicIpChecker(),
    ) {
    }

    /**
     * @param array<string, mixed> $options extra HttpClient options (headers, body, auth_basic)
     *
     * @throws OidcException
     *
     * @return array{status: int, data: array<string, mixed>}
     */
    public function requestJson(string $method, string $url, string $label, array $options = []): array
    {
        $pinned = $this->pinnedAddress($url, $label);
        $max    = $this->options->maxResponseBytes;

        $options['timeout']       = $this->options->timeout;
        $options['max_duration']  = $this->options->maxDuration;
        $options['max_redirects'] = 0;
        $options['headers']       = ['Accept' => 'application/json'] + (is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $options['on_progress']   = static function (int $downloaded, int $size) use ($max): void {
            if ($downloaded > $max || $size > $max) {
                throw new OidcException(OidcException::REASON_RESPONSE_TOO_BIG, 'the response is larger than oidc.max_response_bytes.');
            }
        };
        if ($pinned !== null) {
            $options['resolve'] = [$pinned['host'] => $pinned['ip']];
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                throw new OidcException(OidcException::REASON_HTTP, sprintf('the %s endpoint answered with a redirect, which is never followed.', $label));
            }
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof OidcException) {
                throw $previous;
            }

            throw new OidcException(OidcException::REASON_HTTP, sprintf('the %s request failed (%s).', $label, $e::class), $e);
        }

        if (strlen($content) > $max) {
            throw new OidcException(OidcException::REASON_RESPONSE_TOO_BIG, 'the response is larger than oidc.max_response_bytes.');
        }

        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OidcException(OidcException::REASON_HTTP, sprintf('the %s endpoint did not answer JSON.', $label), $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new OidcException(OidcException::REASON_HTTP, sprintf('the %s endpoint did not answer a JSON object.', $label));
        }

        /** @var array<string, mixed> $typed */
        $typed = $data;

        return ['status' => $status, 'data' => $typed];
    }

    /**
     * Checks the URL and every address of its host; returns the address to connect to (null for an IP literal).
     *
     * @return array{host: string, ip: string}|null
     */
    private function pinnedAddress(string $url, string $label): ?array
    {
        try {
            $this->urlValidator->assertSafeHttpsUrl($url, $label);
        } catch (InvalidArgumentException $e) {
            throw new OidcException(OidcException::REASON_ENDPOINT, $e->getMessage(), $e);
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->ipChecker->isPublic($host)) {
                throw new OidcException(OidcException::REASON_ENDPOINT, sprintf('the %s endpoint is not a public address.', $label));
            }

            return null;
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new OidcException(OidcException::REASON_ENDPOINT, sprintf('the %s endpoint host does not resolve.', $label));
        }
        foreach ($addresses as $ip) {
            if (!$this->ipChecker->isPublic($ip)) {
                throw new OidcException(OidcException::REASON_ENDPOINT, sprintf('the %s endpoint host resolves to a private or reserved address.', $label));
            }
        }

        return ['host' => $host, 'ip' => $addresses[0]];
    }
}
