<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * Renders the login page consumed by Symfony form_login.
 */
final class LoginController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RegistrationGate $registrationGate,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function login(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if ($this->tokenStorage->getToken()?->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            $target = $profile->loginSuccessRoute ?? 'homepage';

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        $form = $this->formFactory->create(LoginFormType::class, null, [
            'action'  => $this->urlGenerator->generate($profile->routes['login']['name']),
            'method'  => 'POST',
            'profile' => $profile->name,
        ]);
        $lastUsername = $this->authenticationUtils->getLastUsername();
        if ($lastUsername !== '') {
            $form->get('_username')->setData($lastUsername);
        }

        $content = $this->twig->render($profile->templates['login'], [
            'login_form'             => $form->createView(),
            'error'                  => $this->authenticationUtils->getLastAuthenticationError(),
            'register_route'         => $profile->routes['register']['name'],
            'reset_password_route'   => $profile->routes['reset_request']['name'],
            'password_reset_enabled' => $profile->passwordReset['mode'] === PasswordResetMode::Enabled->value,
            'registration_allowed'   => $this->registrationGate->isRegistrationAllowed($profile->name),
            'layout_template'        => $profile->templates['layout'],
        ]);

        return new Response($content);
    }
}
