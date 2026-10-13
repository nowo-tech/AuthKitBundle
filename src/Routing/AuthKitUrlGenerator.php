<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates Auth Kit route URLs with optional {_locale} parameters.
 *
 * Absolute URLs (ABSOLUTE_URL / NETWORK_PATH) go through AbsoluteUrlGenerator, so they use
 * `nowo_auth_kit.absolute_url.base_uri` instead of the request Host when it is configured.
 */
final class AuthKitUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthKitRouteLocaleParameters $routeLocaleParameters,
        private readonly ?AbsoluteUrlGenerator $absoluteUrlGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @param string|null $locale explicit {_locale} (e.g. a mail recipient's); null uses the request locale
     */
    public function generate(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH, ?string $locale = null): string
    {
        $parameters = $this->routeLocaleParameters->merge($parameters, $locale);

        if ($this->absoluteUrlGenerator instanceof AbsoluteUrlGenerator
            && ($referenceType === UrlGeneratorInterface::ABSOLUTE_URL || $referenceType === UrlGeneratorInterface::NETWORK_PATH)
        ) {
            return $this->absoluteUrlGenerator->generate($route, $parameters, $referenceType);
        }

        return $this->urlGenerator->generate($route, $parameters, $referenceType);
    }
}
