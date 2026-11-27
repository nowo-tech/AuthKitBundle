<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc;

use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestException;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host's "Test sign-in" action of the kernel tests (an administrator-only POST in a real application).
 */
final readonly class TestSignInController
{
    public function __construct(private SocialLoginTestStart $tests)
    {
    }

    public function __invoke(Request $request, string $provider): Response
    {
        try {
            $hint = $request->query->get('login_hint');

            return new RedirectResponse($this->tests->start($request, $provider, $hint));
        } catch (SocialLoginTestException $exception) {
            return new Response($exception->reason, Response::HTTP_CONFLICT);
        }
    }
}
