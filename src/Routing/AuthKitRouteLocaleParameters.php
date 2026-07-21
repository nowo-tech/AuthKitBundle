<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Nowo\AuthKitBundle\Enum\LocaleInPathMode;
use Symfony\Component\HttpFoundation\RequestStack;

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
     *
     * @return array<string, mixed>
     */
    public function merge(array $parameters = []): array
    {
        if (!$this->localeInPathMode->usesLocalePrefix() || isset($parameters['_locale'])) {
            return $parameters;
        }

        $request = $this->requestStack->getCurrentRequest();
        $locale  = $request?->attributes->get('_locale');
        if (!is_string($locale) || $locale === '') {
            $locale = $request?->getLocale();
        }
        if (!is_string($locale) || $locale === '') {
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
        $pathPattern = str_replace('\\{token\\}', '[^/]+', $pathPattern);

        return match ($this->localeInPathMode) {
            LocaleInPathMode::Never  => ['^' . $pathPattern],
            LocaleInPathMode::Always => ['^/(' . $this->localeAlternation() . ')' . $pathPattern],
            LocaleInPathMode::Both   => [
                '^/(' . $this->localeAlternation() . ')' . $pathPattern,
                '^' . $pathPattern,
            ],
        };
    }

    private function localeAlternation(): string
    {
        $locales = array_map(static fn (string $locale): string => preg_quote($locale, '/'), $this->enabledLocales);

        return implode('|', $locales);
    }
}
