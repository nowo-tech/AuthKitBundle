<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\MagicLoginRequestFormType;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * Renders the magic-login request page (identifier / email → passwordless link).
 */
final class MagicLoginRequestController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly MagicLoginGate $magicLoginGate,
        private readonly MagicLoginRequestHandler $requestHandler,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
    ) {
    }

    public function request(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        if (!$this->magicLoginGate->isEnabled($profile->name)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $form = $this->formFactory->create(MagicLoginRequestFormType::class, null, [
            'profile' => $profile->name,
        ]);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string} $data */
            $data = $form->getData();
            $this->requestHandler->handle($data['identifier'], $profile->name);

            $this->flashMessenger->flash($request, 'success', 'magic_login.request.flash_sent');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $formView = $form->createView();
        $page     = $this->pages->create('magic_login_request', $profile, $formView, [
            $this->pages->link('magic_login.request.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ]);

        $content = $this->twig->render($profile->template('magic_login_request'), $this->pages->context('magic_login_request', $page, $profile, [
            'magic_login_form' => $formView,
            'login_route'      => $profile->routes['login']['name'],
        ]));

        return new Response($content);
    }
}
