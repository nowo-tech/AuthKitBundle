<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function is_string;

/**
 * GET interstitial when {@code magic_login.confirm_interstitial} is enabled (login_link check_post_only).
 *
 * POST is handled by Symfony's login_link authenticator and must not reach this action.
 */
final class MagicLoginConfirmController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function check(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            throw new LogicException('Magic login POST is handled by the login_link authenticator.');
        }

        $profile = $this->profileResolver->resolve($request);
        if (!($profile->magicLogin['confirm_interstitial'] ?? false)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['magic_login_request']['name']),
            ]);
        }

        $params = [];
        foreach (['user', 'expires', 'hash'] as $key) {
            $value = $request->query->get($key);
            if (is_string($value) && $value !== '') {
                $params[$key] = $value;
            }
        }

        if ($params === []) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['magic_login_request']['name']),
            ]);
        }

        // Plain POST form: login_link consumes the signed POST before FormKit CSRF can run.
        $content = $this->twig->render($profile->templates['magic_login_confirm'], [
            'action'          => $request->getPathInfo(),
            'params'          => $params,
            'login_route'     => $profile->routes['login']['name'],
            'layout_template' => $profile->templates['layout'],
        ]);

        $response = new Response($content);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
