<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;

/**
 * Resolves {_locale} route parameters for Auth Kit URLs and access_control patterns.
 */
final class AuthKitRouteLocaleParameters
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly bool $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function merge(array $parameters = []): array
    {
        if (!$this->localeInPath || isset($parameters['_locale'])) {
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

    public function accessControlPattern(string $path): string
    {
        $pathPattern = preg_quote($path, '/');
        $pathPattern = str_replace('\\{token\\}', '[^/]+', $pathPattern);

        if (!$this->localeInPath) {
            return '^' . $pathPattern;
        }

        $locales = array_map(static fn (string $locale): string => preg_quote($locale, '/'), $this->enabledLocales);

        return '^/(' . implode('|', $locales) . ')' . $pathPattern;
    }
}
