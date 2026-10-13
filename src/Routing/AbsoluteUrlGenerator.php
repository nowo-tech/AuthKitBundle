<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use InvalidArgumentException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function array_merge;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Builds absolute URLs that leave the request (mailed reset / magic links, QR payloads, OAuth redirect_uri)
 * against the fixed origin configured in `nowo_auth_kit.absolute_url.base_uri` instead of the incoming
 * `Host` header (password-reset poisoning hardening).
 *
 * The router context is swapped only for the duration of one generation and always restored in `finally`,
 * so no state leaks between requests in long-running workers (FrankenPHP worker mode).
 * With no base URI configured every call is a plain pass-through (pre-1.24 behaviour).
 */
final class AbsoluteUrlGenerator
{
    private readonly ?string $scheme;

    private readonly string $host;

    private readonly ?int $port;

    private readonly string $basePath;

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        ?string $baseUri = null,
    ) {
        $baseUri = $baseUri === null ? '' : trim($baseUri);
        if ($baseUri === '') {
            $this->scheme   = null;
            $this->host     = '';
            $this->port     = null;
            $this->basePath = '';

            return;
        }

        $error = self::validationError($baseUri);
        if ($error !== null) {
            throw new InvalidArgumentException(sprintf('Invalid "nowo_auth_kit.absolute_url.base_uri" value "%s": %s', $baseUri, $error));
        }

        /** @var array{scheme: string, host: string, port?: int, path?: string} $parts */
        $parts          = parse_url($baseUri);
        $this->scheme   = strtolower($parts['scheme']);
        $this->host     = $parts['host'];
        $this->port     = $parts['port'] ?? null;
        $this->basePath = rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Returns null when $baseUri is a usable base (absolute http(s) URL, optional port and path prefix,
     * no user info, query or fragment), otherwise a human-readable reason.
     */
    public static function validationError(string $baseUri): ?string
    {
        $parts = parse_url($baseUri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'expected an absolute URL such as "https://app.example.com".';
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'the scheme must be http or https.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'user info is not allowed.';
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'query strings and fragments are not allowed.';
        }

        return null;
    }

    public function isEnabled(): bool
    {
        return $this->scheme !== null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_URL): string
    {
        return $this->withBaseContext(fn (): string => $this->router->generate($name, $parameters, $referenceType));
    }

    /**
     * Runs $callback with the router context pointed at the configured base (scheme, host, port, base path),
     * keeping the current context parameters (e.g. `_locale`) merged with $contextParameters.
     * Without a base URI the callback runs untouched.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @param array<string, mixed> $contextParameters
     *
     * @return T
     */
    public function withBaseContext(callable $callback, array $contextParameters = []): mixed
    {
        if ($this->scheme === null) {
            return $callback();
        }

        $original = $this->router->getContext();
        $context  = new RequestContext(
            $this->basePath,
            $original->getMethod(),
            $this->host,
            $this->scheme,
            $this->scheme === 'http' && is_int($this->port) ? $this->port : 80,
            $this->scheme === 'https' && is_int($this->port) ? $this->port : 443,
            $original->getPathInfo(),
            $original->getQueryString(),
        );
        $context->setParameters(array_merge($original->getParameters(), $contextParameters));

        $this->router->setContext($context);

        try {
            return $callback();
        } finally {
            $this->router->setContext($original);
        }
    }

    /**
     * True when $value is an unresolved container env placeholder (validated at runtime instead).
     */
    public static function isEnvPlaceholder(mixed $value): bool
    {
        return is_string($value) && preg_match('/env_[a-f0-9]{16}_\w+_[a-f0-9]{32}/', $value) === 1;
    }
}
