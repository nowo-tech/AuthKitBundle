<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers for locale-aware Auth Kit route parameters.
 */
final class AuthKitRoutingExtension
{
    public function __construct(
        private readonly AuthKitRouteLocaleParameters $routeLocaleParameters,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction('auth_kit_route_params')]
    public function routeParameters(array $parameters = []): array
    {
        return $this->routeLocaleParameters->merge($parameters);
    }
}
