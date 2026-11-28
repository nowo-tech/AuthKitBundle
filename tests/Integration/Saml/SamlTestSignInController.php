<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Nowo\AuthKitBundle\Saml\SamlTestStart;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host's SAML "Test sign-in" action of the kernel tests (an administrator-only POST in a real application).
 */
final readonly class SamlTestSignInController
{
    public function __construct(private SamlTestStart $tests)
    {
    }

    public function __invoke(Request $request, string $provider): Response
    {
        try {
            return new RedirectResponse($this->tests->start($request, $provider, $request->query->get('login_hint')));
        } catch (SocialLoginTestException $exception) {
            return new Response($exception->reason . ($this->tests->hasHandler() ? '' : ' (no handler)'), Response::HTTP_CONFLICT);
        }
    }
}
