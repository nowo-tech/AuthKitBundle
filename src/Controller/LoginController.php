<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\LoginFormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * Renders the login page consumed by Symfony form_login.
 */
final class LoginController
{
    /**
     * @param array{layout: string, login: string, register: string} $templates
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly array $templates,
        private readonly array $routes,
        private readonly ?string $loginSuccessRoute,
    ) {
    }

    public function login(): Response
    {
        if ($this->tokenStorage->getToken()?->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            $target = $this->loginSuccessRoute ?? 'homepage';

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }
        $form = $this->formFactory->create(LoginFormType::class, null, [
            'action' => $this->urlGenerator->generate($this->routes['login']['name']),
            'method' => 'POST',
        ]);
        $lastUsername = $this->authenticationUtils->getLastUsername();
        if ($lastUsername !== '') {
            $form->get('_username')->setData($lastUsername);
        }
        $content = $this->twig->render($this->templates['login'], [
            'login_form'      => $form->createView(),
            'error'           => $this->authenticationUtils->getLastAuthenticationError(),
            'register_route'  => $this->routes['register']['name'],
            'layout_template' => $this->templates['layout'],
        ]);

        return new Response($content);
    }
}
