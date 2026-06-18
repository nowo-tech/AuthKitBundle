<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * Completes password reset via link token (email, magic link, deep link).
 */
final class ResetPasswordController
{
    /**
     * @param array{layout: string, login: string, register: string, reset_request: string, reset_password: string, reset_password_code: string} $templates
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly PasswordResetGate $passwordResetGate,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly PasswordResetCompleter $passwordResetCompleter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly array $templates,
        private readonly array $routes,
        private readonly ?string $loginSuccessRoute,
    ) {
    }

    public function reset(Request $request, string $token): Response
    {
        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            $target = $this->loginSuccessRoute ?? $this->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        if (!$this->passwordResetGate->isEnabled()) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($this->routes['login']['name']),
            ]);
        }

        $user = $this->tokenManager->resolveUserByLinkToken($token);

        if ($user === null) {
            if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
                $request->getSession()->getFlashBag()->add('error', 'reset.password.flash_invalid_token');
            }

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($this->routes['reset_request']['name']),
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

            if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
                $request->getSession()->getFlashBag()->add('success', 'reset.password.flash_success');
            }

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($this->routes['login']['name']),
            ]);
        }

        $content = $this->twig->render($this->templates['reset_password'], [
            'reset_password_form' => $form->createView(),
            'login_route'         => $this->routes['login']['name'],
            'layout_template'     => $this->templates['layout'],
        ]);

        return new Response($content);
    }
}
