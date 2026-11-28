<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The absolute URLs of a provider's SP side (2.10.0): ACS, metadata and the default SP entity id (the metadata URL).
 * Built against `nowo_auth_kit.absolute_url.base_uri` when it is set (recommended: the ACS URL is then not derived from
 * the request's Host), never with a locale (the SAML routes are not localized). Stateless.
 *
 * @internal
 */
final class SamlUrls
{
    public function __construct(
        private readonly AbsoluteUrlGenerator $urls,
    ) {
    }

    public function acsUrl(ProfileSettings $profile, string $provider): string
    {
        return $this->url($profile, 'acs', $provider);
    }

    public function metadataUrl(ProfileSettings $profile, string $provider): string
    {
        return $this->url($profile, 'metadata', $provider);
    }

    public function spEntityId(ProfileSettings $profile, SamlProvider $provider): string
    {
        return $provider->getSpEntityId() ?? $this->metadataUrl($profile, $provider->getProvider());
    }

    private function url(ProfileSettings $profile, string $route, string $provider): string
    {
        /** @var array<string, array{name: string}> $routes */
        $routes = $profile->saml['routes'] ?? [];

        return $this->urls->generate($routes[$route]['name'], ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
