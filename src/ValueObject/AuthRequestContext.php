<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use Closure;
use Nowo\AuthKitBundle\Internal\HiddenValues;

use function array_key_exists;
use function is_scalar;
use function strtolower;

/**
 * Framework-free view of the HTTP request a 1.46 port or event is about (REQ-ARCH-001 rule 3), instead of the Symfony
 * `Request`.
 *
 * Its properties hold what can be shown: method, path, route, client IP, user agent, locale, firewall and AuthKit
 * profile. Request values (headers, query and form fields) and the session are read through methods; the request and
 * the session themselves are **not** properties (internal {@see \WeakMap}), so a dump or `serialize()` of the VO never
 * shows a cookie, a CSRF token or a session value, and a `clone` or an unserialized copy reads nothing.
 *
 * Built per call by the bundle and never stored (FrankenPHP worker mode, kernel reset false). Your tests can build one
 * from plain arrays with {@see self::fromValues()}.
 */
final readonly class AuthRequestContext
{
    private const READER = 'reader';

    /**
     * @param string $method HTTP method, upper case
     * @param string $path path info (no query string)
     * @param string|null $routeName the matched route, null when unknown
     * @param string|null $clientIp the client IP the application trusts (Symfony trusted proxies applied)
     * @param string|null $userAgent the User-Agent header
     * @param string $locale the request locale
     * @param string|null $firewall the firewall name, null when unknown
     * @param string|null $profile the AuthKit profile name, null when unknown
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $routeName = null,
        public ?string $clientIp = null,
        public ?string $userAgent = null,
        public string $locale = '',
        public ?string $firewall = null,
        public ?string $profile = null,
    ) {
    }

    /**
     * A context whose values come from plain arrays (tests, or a host that builds one itself). The session array is
     * copied: {@see self::sessionRemove()} removes from that copy only.
     *
     * @param array<string, string> $headers header name (any case) => value
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $session
     */
    public static function fromValues(
        string $method,
        string $path,
        ?string $routeName = null,
        ?string $clientIp = null,
        ?string $userAgent = null,
        string $locale = '',
        ?string $firewall = null,
        ?string $profile = null,
        array $headers = [],
        array $query = [],
        array $post = [],
        array $session = [],
    ): self {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }

        $reader = new class($lower, $query, $post, $session) {
            /**
             * @param array<string, string> $headers
             * @param array<string, mixed> $query
             * @param array<string, mixed> $post
             * @param array<string, mixed> $session
             */
            public function __construct(private readonly array $headers, private readonly array $query, private readonly array $post, private array $session)
            {
            }

            public function read(string $bag, string $name): mixed
            {
                return match ($bag) {
                    'header' => $this->headers[strtolower($name)] ?? null,
                    'query'  => $this->query[$name] ?? null,
                    'post'   => $this->post[$name] ?? null,
                    default  => $this->session[$name] ?? null,
                };
            }

            public function has(string $name): bool
            {
                return array_key_exists($name, $this->session);
            }

            public function remove(string $name): mixed
            {
                $value = $this->session[$name] ?? null;
                unset($this->session[$name]);

                return $value;
            }
        };

        return self::withReader(
            new self($method, $path, $routeName, $clientIp, $userAgent, $locale, $firewall, $profile),
            $reader->read(...),
            $reader->has(...),
            $reader->remove(...),
        );
    }

    /**
     * Attaches the hidden readers (used by the bundle's request adapter).
     *
     * @param Closure(string, string): mixed $read bag ('header', 'query', 'post', 'session') and name => value
     * @param Closure(string): bool $has whether the session has the attribute
     * @param Closure(string): mixed $remove removes a session attribute and returns its value
     *
     * @internal
     */
    public static function withReader(self $context, Closure $read, Closure $has, Closure $remove): self
    {
        HiddenValues::put($context, [self::READER => [$read, $has, $remove]]);

        return $context;
    }

    /** A request header (first value), or null. */
    public function header(string $name): ?string
    {
        return $this->scalar($this->read('header', $name));
    }

    /** A query-string value, or null when absent or not a scalar. */
    public function query(string $name): ?string
    {
        return $this->scalar($this->read('query', $name));
    }

    /** A submitted form field, or null when absent or not a scalar. */
    public function post(string $name): ?string
    {
        return $this->scalar($this->read('post', $name));
    }

    /** Whether the session holds the attribute (false without a session). */
    public function sessionHas(string $name): bool
    {
        $readers = $this->readers();

        return $readers !== null && $readers[1]($name) === true;
    }

    /** A session attribute, or null (also without a session). */
    public function sessionGet(string $name): mixed
    {
        return $this->read('session', $name);
    }

    /** Removes a session attribute and returns its former value (null without a session). */
    public function sessionRemove(string $name): mixed
    {
        $readers = $this->readers();

        return $readers === null ? null : $readers[2]($name);
    }

    private function read(string $bag, string $name): mixed
    {
        $readers = $this->readers();

        return $readers === null ? null : $readers[0]($bag, $name);
    }

    /**
     * @return array{0: Closure(string, string): mixed, 1: Closure(string): bool, 2: Closure(string): mixed}|null
     */
    private function readers(): ?array
    {
        /** @var array{0: Closure(string, string): mixed, 1: Closure(string): bool, 2: Closure(string): mixed}|null $readers */
        $readers = HiddenValues::get($this, self::READER);

        return $readers;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
