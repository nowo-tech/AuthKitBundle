<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml\Controller;

use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SamlProviderRepository;
use Nowo\AuthKitBundle\Saml\SamlSpMetadata;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * The SP metadata of a provider row (2.10.0), for the IdP administrator: any complete row, enabled or not (it is set up
 * at the IdP before it is enabled). Public data only (no key). 404 for an unknown or incomplete row.
 */
final class SamlMetadataController
{
    public function __construct(
        private readonly RequestProfileResolver $profileResolver,
        private readonly SamlProviderRepository $providers,
        private readonly SamlSpMetadata $metadata,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route(path: '/saml/{provider}/metadata', name: 'nowo_auth_kit_saml_metadata', requirements: ['provider' => SamlLoginController::PROVIDER_REQUIREMENT], methods: ['GET'])]
    public function metadata(Request $request, string $provider): Response
    {
        $profile = $this->profileResolver->resolve($request);
        $row     = $profile->samlEnabled() ? $this->providers->findOneByProvider($provider) : null;
        if (!$row instanceof SamlProvider || !$row->isComplete()) {
            throw new NotFoundHttpException('No SAML provider has this key.');
        }

        try {
            $xml = $this->metadata->build($row, $profile);
        } catch (Throwable $exception) {
            $this->logger?->warning('AuthKit SAML metadata of provider {provider} could not be built.', ['provider' => $provider, 'exception' => $exception::class]);

            throw new NotFoundHttpException('No SAML provider has this key.', $exception);
        }

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type'  => 'application/samlmetadata+xml; charset=UTF-8',
            'Cache-Control' => 'no-cache, private',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }
}
