<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Nowo\AuthKitBundle\Enum\LocaleInPathMode;
use Symfony\Component\HttpFoundation\RequestStack;

use function in_array;
use function is_bool;
use function is_string;

/**
 * Resolves {_locale} route parameters for Auth Kit URLs and access_control patterns.
 */
final class AuthKitRouteLocaleParameters
{
    private readonly LocaleInPathMode $localeInPathMode;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        string|bool $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
    ) {
        $this->localeInPathMode = is_bool($localeInPath)
            ? ($localeInPath ? LocaleInPathMode::Always : LocaleInPathMode::Never)
            : LocaleInPathMode::from($localeInPath);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param string|null $locale explicit locale (e.g. a mail recipient's) instead of the request locale
     *
     * @return array<string, mixed>
     */
    public function merge(array $parameters = [], ?string $locale = null): array
    {
        if (!$this->localeInPathMode->usesLocalePrefix() || isset($parameters['_locale'])) {
            return $parameters;
        }

        if ($locale !== null && $this->isEnabledLocale($locale)) {
            return ['_locale' => $locale] + $parameters;
        }

        $request = $this->requestStack->getCurrentRequest();
        $locale  = $request?->attributes->get('_locale');
        if (!is_string($locale) || !$this->isEnabledLocale($locale)) {
            $locale = $request?->getLocale();
        }
        if (!is_string($locale) || !$this->isEnabledLocale($locale)) {
            $locale = $this->defaultLocale;
        }

        return ['_locale' => $locale] + $parameters;
    }

    /**
     * Primary access_control regex (localized when locale prefixes are used, else bare).
     */
    public function accessControlPattern(string $path): string
    {
        $patterns = $this->accessControlPatterns($path);

        return $patterns[0];
    }

    /**
     * All access_control regexes for a bare path (one or two when in_path=both).
     *
     * @return list<string>
     */
    public function accessControlPatterns(string $path): array
    {
        $pathPattern = preg_quote($path, '/');
        $pathPattern = str_replace(['\\{token\\}', '\\{provider\\}', '\\{id\\}'], '[^/]+', $pathPattern);

        return match ($this->localeInPathMode) {
            LocaleInPathMode::Never  => ['^' . $pathPattern],
            LocaleInPathMode::Always => ['^/(' . $this->localeAlternation() . ')' . $pathPattern],
            LocaleInPathMode::Both   => [
                '^/(' . $this->localeAlternation() . ')' . $pathPattern,
                '^' . $pathPattern,
            ],
        };
    }

    /**
     * SEC-004 L3: only an enabled locale goes into a generated path. A request locale taken from input (header,
     * query, host listener) such as `/evil.example` would otherwise turn `/{_locale}/login` into the protocol-relative
     * `//evil.example/login` when the router does not enforce requirements.
     */
    private function isEnabledLocale(string $locale): bool
    {
        if ($locale === '') {
            return false;
        }

        return $this->enabledLocales === [] ? preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) === 1 : in_array($locale, $this->enabledLocales, true);
    }

    private function localeAlternation(): string
    {
        $locales = array_map(static fn (string $locale): string => preg_quote($locale, '/'), $this->enabledLocales);

        return implode('|', $locales);
    }
}
