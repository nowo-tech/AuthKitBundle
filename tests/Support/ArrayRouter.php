<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Router over a fixed route collection (unit tests).
 */
final class ArrayRouter implements RouterInterface
{
    private RequestContext $context;

    public function __construct(private readonly RouteCollection $routes, ?RequestContext $context = null)
    {
        $this->context = $context ?? new RequestContext();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return (new UrlGenerator($this->routes, $this->context))->generate($name, $parameters, $referenceType);
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        return (new UrlMatcher($this->routes, $this->context))->match($pathinfo);
    }
}
