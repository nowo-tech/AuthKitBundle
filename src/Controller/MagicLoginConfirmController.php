<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\MagicLoginConfirmType;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
    ) {
    }

    #[Route(path: '/magic-login/check', name: 'nowo_auth_kit_magic_login_check', methods: ['GET'])]
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

        return $this->renderConfirm($profile->template('magic_login_confirm'), $form->createView(), $profile, $params);
    }

    #[Route(path: '/magic-login/confirm', name: 'nowo_auth_kit_magic_login_confirm', methods: ['POST'])]
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
                $profile->template('magic_login_confirm'),
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
            $this->flashMessenger->flash($request, 'error', 'magic_login.confirm.flash_invalid');

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
        // data-turbo="false" on the panel (Turbo honours it on ancestors): the one-time link is posted as a full navigation.
        $page = $this->pages->create('magic_login_confirm', $profile, $formView, [
            $this->pages->link('magic_login.confirm.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ], attributes: ['data-turbo' => 'false']);

        $content = $this->twig->render($template, $this->pages->context('magic_login_confirm', $page, $profile, [
            'magic_login_confirm_form' => $formView,
            'action'                   => $this->urlGenerator->generate($profile->routes['magic_login_confirm']['name']),
            'params'                   => $params,
            'login_route'              => $profile->routes['login']['name'],
        ]));

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
