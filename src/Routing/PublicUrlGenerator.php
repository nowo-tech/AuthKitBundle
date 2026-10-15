<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * `UrlGeneratorInterface` for links that leave the request (mails, chat / webhook payloads, share links,
 * exports): absolute URLs and network paths come from {@see AbsoluteUrlGenerator} on
 * `nowo_auth_kit.absolute_url.base_uri` (e.g. `%env(DEFAULT_URI)%`), never from the request `Host`, so a
 * forged header cannot end up in a mail generated during that request. Relative paths, and every URL when
 * no base URI is configured, use the router.
 *
 * Inject it where host code builds such links (service id `nowo_auth_kit.public_url_generator`, or
 * `#[Autowire(service: PublicUrlGenerator::class)] UrlGeneratorInterface $urlGenerator`).
 */
final readonly class PublicUrlGenerator implements UrlGeneratorInterface
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private AbsoluteUrlGenerator $absoluteUrlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if ($referenceType === self::ABSOLUTE_URL || $referenceType === self::NETWORK_PATH) {
            return $this->absoluteUrlGenerator->generate($name, $parameters, $referenceType);
        }

        return $this->router->generate($name, $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        $this->router->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->router->getContext();
    }
}
