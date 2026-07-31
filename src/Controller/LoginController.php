<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
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
        private readonly PasswordResetGate $passwordResetGate,
        private readonly MagicLoginGate $magicLoginGate,
        private readonly SocialLoginGate $socialLoginGate,
        private readonly QrLoginGate $qrLoginGate,
        private readonly SocialLoginCredentialRepository $socialLoginCredentials,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function login(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
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

        $credentials = $this->socialLoginGate->isEnabled($profile->name)
            ? $this->socialLoginCredentials->findEnabledOrdered()
            : [];

        $socialProviders = [];
        $ssoProviders    = [];
        foreach ($credentials as $credential) {
            if ($credential->isEnterpriseSso()) {
                $ssoProviders[] = $credential;
            } else {
                $socialProviders[] = $credential;
            }
        }

        $content = $this->twig->render($profile->templates['login'], [
            'login_form'             => $form->createView(),
            'error'                  => $this->authenticationUtils->getLastAuthenticationError(),
            'register_route'         => $profile->routes['register']['name'],
            'reset_password_route'   => $profile->routes['reset_request']['name'],
            'magic_login_route'      => $profile->routes['magic_login_request']['name'],
            'social_login_route'     => $profile->routes['social_login_start']['name'],
            'password_reset_enabled' => $this->passwordResetGate->isEnabled($profile->name),
            'magic_login_enabled'    => $this->magicLoginGate->isEnabled($profile->name),
            'social_login_enabled'   => $socialProviders !== [],
            'social_login_providers' => $socialProviders,
            'sso_login_enabled'      => $ssoProviders !== [],
            'sso_login_providers'    => $ssoProviders,
            'registration_allowed'   => $this->registrationGate->isRegistrationAllowed($profile->name),
            'qr_login_enabled'       => $this->qrLoginGate->isEnabled($profile->name),
            'qr_login_start_route'   => $profile->routes['qr_login_start']['name'],
            'layout_template'        => $profile->templates['layout'],
        ]);

        return new Response($content);
    }
}
