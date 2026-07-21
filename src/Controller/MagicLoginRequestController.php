<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\MagicLoginRequestFormType;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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

            if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
                $request->getSession()->getFlashBag()->add('success', 'magic_login.request.flash_sent');
            }

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $content = $this->twig->render($profile->templates['magic_login_request'], [
            'magic_login_form' => $form->createView(),
            'login_route'      => $profile->routes['login']['name'],
            'layout_template'  => $profile->templates['layout'],
        ]);

        return new Response($content);
    }
}
