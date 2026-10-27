<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Completes password reset with identifier + OTP/code (SMS, email code, authenticator).
 */
final class ResetPasswordCodeController
{
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
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    #[Route(path: '/reset-password/complete', name: 'nowo_auth_kit_reset_password_code', methods: ['GET', 'POST'])]
    public function complete(Request $request): Response
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

        $form = $this->formFactory->create(ResetPasswordCodeFormType::class, null, [
            'profile' => $profile->name,
        ]);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string, code: string, password: string} $data */
            $data = $form->getData();
            $user = $this->tokenManager->resolveUserByIdentifierAndCode($data['identifier'], $data['code'], $profile->name);

            if ($user === null) {
                $form->get('code')->addError(new FormError(
                    $this->translator?->trans('reset.code.flash_invalid', [], NowoAuthKitBundle::TRANSLATION_DOMAIN) ?? 'reset.code.flash_invalid',
                    'reset.code.flash_invalid',
                ));

                return $this->renderPage($form->createView(), $profile);
            }

            if ($user instanceof PasswordAuthenticatedUserInterface) {
                $this->passwordResetCompleter->complete($user, $data['password']);
            }

            $this->flashMessenger->flash($request, 'success', 'reset.password.flash_success');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        return $this->renderPage($form->createView(), $profile);
    }

    private function renderPage(FormView $formView, ProfileSettings $profile): Response
    {
        $page = $this->pages->create('reset_password_code', $profile, $formView, [
            $this->pages->link('reset.code.request_new', $this->urlGenerator->generate($profile->routes['reset_request']['name'])),
            $this->pages->link('reset.code.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ]);

        return new Response($this->twig->render($profile->template('reset_password_code'), $this->pages->context('reset_password_code', $page, $profile, [
            'reset_password_code_form' => $formView,
            'login_route'              => $profile->routes['login']['name'],
            'reset_request_route'      => $profile->routes['reset_request']['name'],
        ])));
    }
}
