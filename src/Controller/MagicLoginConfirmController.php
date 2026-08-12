<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\MagicLoginConfirmType;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\LoginLink\Exception\ExpiredLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Twig\Environment;

use function is_string;

/**
 * Magic-login confirm interstitial when {@code magic_login.confirm_interstitial} is enabled.
 *
 * GET on {@code magic_login_check} renders a FormKit form (CSRF + signed hiddens).
 * POST on {@code magic_login_confirm} validates CSRF, consumes the login link, then logs the user in.
 * Pair with firewall {@code login_link.check_post_only} so the email GET does not authenticate.
 */
final class MagicLoginConfirmController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly Security $security,
        private readonly ?LoginLinkHandlerInterface $loginLinkHandler = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function check(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);
        if (!($profile->magicLogin['confirm_interstitial'] ?? false)) {
            return $this->redirectToRoute($profile->routes['magic_login_request']['name']);
        }

        $params = $this->signedParamsFromQuery($request);
        if ($params === []) {
            return $this->redirectToRoute($profile->routes['magic_login_request']['name']);
        }

        $form = $this->formFactory->create(MagicLoginConfirmType::class, $params, [
            'action' => $this->urlGenerator->generate($profile->routes['magic_login_confirm']['name']),
        ]);

        return $this->renderConfirm($profile->templates['magic_login_confirm'], $form->createView(), $profile, $params);
    }

    public function confirm(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);
        if (!($profile->magicLogin['confirm_interstitial'] ?? false)) {
            return $this->redirectToRoute($profile->routes['magic_login_request']['name']);
        }

        $form = $this->formFactory->create(MagicLoginConfirmType::class, null, [
            'action' => $this->urlGenerator->generate($profile->routes['magic_login_confirm']['name']),
        ]);
        $form->handleRequest($request);

        /** @var array{user?: string, expires?: string, hash?: string} $data */
        $data   = $form->getData() ?? [];
        $params = [];
        foreach (['user', 'expires', 'hash'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $params[$key] = $value;
            }
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderConfirm(
                $profile->templates['magic_login_confirm'],
                $form->createView(),
                $profile,
                $params,
            );
        }

        if (!$this->loginLinkHandler instanceof LoginLinkHandlerInterface) {
            $this->logger->warning('Magic login confirm skipped: firewall login_link handler is not configured.');

            return $this->redirectToRoute($profile->routes['magic_login_request']['name']);
        }

        try {
            $user = $this->loginLinkHandler->consumeLoginLink($request);
        } catch (InvalidLoginLinkException|ExpiredLoginLinkException) {
            if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
                $request->getSession()->getFlashBag()->add('error', 'magic_login.confirm.flash_invalid');
            }

            return $this->redirectToRoute($profile->routes['magic_login_request']['name']);
        }

        $response = $this->security->login($user, 'login_link', $profile->firewall);
        if ($response instanceof Response) {
            return $response;
        }

        $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

        return $this->redirectToRoute($target);
    }

    /**
     * @return array{user?: string, expires?: string, hash?: string}
     */
    private function signedParamsFromQuery(Request $request): array
    {
        $params = [];
        foreach (['user', 'expires', 'hash'] as $key) {
            $value = $request->query->get($key);
            if (is_string($value) && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * @param array{user?: string, expires?: string, hash?: string} $params
     */
    private function renderConfirm(string $template, FormView $formView, ProfileSettings $profile, array $params): Response
    {
        $content = $this->twig->render($template, [
            'magic_login_confirm_form' => $formView,
            'action'                   => $this->urlGenerator->generate($profile->routes['magic_login_confirm']['name']),
            'params'                   => $params,
            'login_route'              => $profile->routes['login']['name'],
            'layout_template'          => $profile->templates['layout'],
        ]);

        $response = new Response($content);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function redirectToRoute(string $routeName): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate($routeName),
        ]);
    }
}
