<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\ResetPasswordRequestFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * Renders the password reset request page (identifier / email).
 */
final class ResetPasswordRequestController
{
    /**
     * @param array{layout: string, login: string, register: string, reset_request: string, reset_password: string, reset_password_code: string} $templates
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly PasswordResetGate $passwordResetGate,
        private readonly PasswordResetRequestHandler $requestHandler,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly array $templates,
        private readonly array $routes,
        private readonly ?string $loginSuccessRoute,
    ) {
    }

    public function request(Request $request): Response
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

        $form = $this->formFactory->create(ResetPasswordRequestFormType::class);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string} $data */
            $data = $form->getData();
            $this->requestHandler->handle($data['identifier']);

            if ($request->hasSession() && $request->getSession() instanceof FlashBagAwareSessionInterface) {
                $request->getSession()->getFlashBag()->add('success', 'reset.request.flash_sent');
            }

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($this->routes['login']['name']),
            ]);
        }

        $content = $this->twig->render($this->templates['reset_request'], [
            'reset_request_form' => $form->createView(),
            'login_route'        => $this->routes['login']['name'],
            'layout_template'    => $this->templates['layout'],
        ]);

        return new Response($content);
    }
}
