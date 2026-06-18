<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates Auth Kit route URLs with optional {_locale} parameters.
 */
final class AuthKitUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthKitRouteLocaleParameters $routeLocaleParameters,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->urlGenerator->generate(
            $route,
            $this->routeLocaleParameters->merge($parameters),
            $referenceType,
        );
    }
}
