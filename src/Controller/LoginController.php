<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

use function array_map;

/**
 * Renders the login page consumed by Symfony form_login (UiKit page/auth, REQ-UI-003).
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
        private readonly OutboundMailReadyCheckerInterface $outboundMailReadyChecker = new AlwaysOutboundMailReadyChecker(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
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

        $registrationAllowed  = $this->registrationGate->isRegistrationAllowed($profile->name);
        $passwordResetEnabled = $this->passwordResetGate->isEnabled($profile->name);
        $magicLoginEnabled    = $this->magicLoginGate->isEnabled($profile->name);
        $qrLoginEnabled       = $this->qrLoginGate->isEnabled($profile->name);
        $error                = $this->authenticationUtils->getLastAuthenticationError();
        $formView             = $form->createView();

        $mailReady = $this->outboundMailReadyChecker->isReady();
        $links     = [];
        if ($mailReady && $magicLoginEnabled) {
            $links[] = $this->pages->link('login.magic_login_link', $this->urlGenerator->generate($profile->routes['magic_login_request']['name']));
        }
        if ($qrLoginEnabled) {
            $links[] = $this->pages->link('login.qr_login_link', $this->urlGenerator->generate($profile->routes['qr_login_start']['name']));
        }
        if ($mailReady && $passwordResetEnabled) {
            $links[] = $this->pages->link('login.forgot_password_link', $this->urlGenerator->generate($profile->routes['reset_request']['name']));
        }
        if ($registrationAllowed) {
            $links[] = $this->pages->link('login.register_link', $this->urlGenerator->generate($profile->routes['register']['name']));
        }

        $page = $this->pages->create('login', $profile, $formView, $links, $this->pages->authenticationError($error));

        $providerLink = fn (SocialLoginCredential $credential, string $key): Link => new Link(
            new Label($key, NowoAuthKitBundle::TRANSLATION_DOMAIN, ['%provider%' => $credential->getLabel()]),
            $this->urlGenerator->generate($profile->routes['social_login_start']['name'], ['provider' => $credential->getProvider()]),
        );

        $content = $this->twig->render($profile->template('login'), $this->pages->context('login', $page, $profile, [
            'login_form'             => $formView,
            'error'                  => $error,
            'register_route'         => $profile->routes['register']['name'],
            'reset_password_route'   => $profile->routes['reset_request']['name'],
            'magic_login_route'      => $profile->routes['magic_login_request']['name'],
            'social_login_route'     => $profile->routes['social_login_start']['name'],
            'password_reset_enabled' => $passwordResetEnabled,
            'magic_login_enabled'    => $magicLoginEnabled,
            'social_login_enabled'   => $socialProviders !== [],
            'social_login_providers' => $socialProviders,
            'sso_login_enabled'      => $ssoProviders !== [],
            'sso_login_providers'    => $ssoProviders,
            'registration_allowed'   => $registrationAllowed,
            'qr_login_enabled'       => $qrLoginEnabled,
            'qr_login_start_route'   => $profile->routes['qr_login_start']['name'],
            'social_links'           => array_map(static fn (SocialLoginCredential $c): Link => $providerLink($c, 'social.continue_with'), $socialProviders),
            'sso_links'              => array_map(static fn (SocialLoginCredential $c): Link => $providerLink($c, 'sso.continue_with'), $ssoProviders),
        ]));

        return new Response($content);
    }
}
