<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function is_string;

/**
 * Completes password reset via link token (email, magic link, deep link).
 */
final class ResetPasswordController
{
    /** Session key prefix of the link token when password_reset.token_in_session is true (suffix: profile name). */
    public const SESSION_TOKEN_KEY = '_nowo_auth_kit.password_reset.token.';

    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly PasswordResetGate $passwordResetGate,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly PasswordResetCompleter $passwordResetCompleter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
    ) {
    }

    #[Route(path: '/reset-password/reset/{token}', name: 'nowo_auth_kit_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, ?string $token = null): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        if (!$this->passwordResetGate->isEnabled($profile->name)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $tokenInSession = ($profile->passwordReset['token_in_session'] ?? false) === true && $request->hasSession();
        $sessionKey     = self::SESSION_TOKEN_KEY . $profile->name;

        if ($tokenInSession && $token !== null && $token !== '') {
            // Keep the token out of the address bar, browser history and Referer: store it, reload without it.
            $request->getSession()->set($sessionKey, $token);

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['reset_password']['name'], ['token' => null]),
            ]);
        }

        if ($tokenInSession) {
            $stored = $request->getSession()->get($sessionKey);
            $token  = is_string($stored) ? $stored : null;
        }

        $user = $token !== null && $token !== ''
            ? $this->tokenManager->resolveUserByLinkToken($token, $profile->name)
            : null;

        if ($user === null) {
            if ($tokenInSession) {
                $request->getSession()->remove($sessionKey);
            }

            $this->flashMessenger->flash($request, 'error', 'reset.password.flash_invalid_token');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['reset_request']['name']),
            ]);
        }

        $form = $this->formFactory->create(ResetPasswordFormType::class);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{password: string} $data */
            $data = $form->getData();

            if ($user instanceof PasswordAuthenticatedUserInterface) {
                $this->passwordResetCompleter->complete($user, $data['password']);
            }

            if ($tokenInSession) {
                $request->getSession()->remove($sessionKey);
            }

            $this->flashMessenger->flash($request, 'success', 'reset.password.flash_success');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $formView = $form->createView();
        $page     = $this->pages->create('reset_password', $profile, $formView, [
            $this->pages->link('reset.password.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ]);

        $content = $this->twig->render($profile->template('reset_password'), $this->pages->context('reset_password', $page, $profile, [
            'reset_password_form' => $formView,
            'login_route'         => $profile->routes['login']['name'],
        ]));

        return new Response($content);
    }
}
