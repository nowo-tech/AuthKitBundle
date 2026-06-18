<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
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
 * Completes password reset with identifier + OTP/code (SMS, email code, authenticator).
 */
final class ResetPasswordCodeController
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

    public function complete(Request $request): Response
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

        $form = $this->formFactory->create(ResetPasswordCodeFormType::class);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string, code: string, password: string} $data */
            $data = $form->getData();
            $user = $this->tokenManager->resolveUserByIdentifierAndCode($data['identifier'], $data['code']);

            if ($user === null) {
                $form->get('code')->addError(new \Symfony\Component\Form\FormError('reset.code.flash_invalid'));

                return new Response($this->twig->render($this->templates['reset_password_code'], [
                    'reset_password_code_form' => $form->createView(),
                    'login_route'              => $this->routes['login']['name'],
                    'reset_request_route'      => $this->routes['reset_request']['name'],
                    'layout_template'          => $this->templates['layout'],
                ]));
            }

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

        $content = $this->twig->render($this->templates['reset_password_code'], [
            'reset_password_code_form' => $form->createView(),
            'login_route'              => $this->routes['login']['name'],
            'reset_request_route'      => $this->routes['reset_request']['name'],
            'layout_template'          => $this->templates['layout'],
        ]);

        return new Response($content);
    }
}
