<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Form\ResetPasswordRequestFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCheckEmail;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function is_string;

/**
 * Renders the password reset request page (identifier / email).
 */
final class ResetPasswordRequestController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly PasswordResetGate $passwordResetGate,
        private readonly PasswordResetRequestHandler $requestHandler,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
    ) {
    }

    #[Route(path: '/reset-password', name: 'nowo_auth_kit_reset_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
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

        $form = $this->formFactory->create(ResetPasswordRequestFormType::class, null, [
            'profile' => $profile->name,
        ]);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{identifier: string} $data */
            $data    = $form->getData();
            $outcome = $this->requestHandler->handle($data['identifier'], $profile->name);

            $afterRequestRoute = $profile->passwordReset['after_request_route'] ?? null;
            if (is_string($afterRequestRoute) && $afterRequestRoute !== '') {
                // Host "check your email" page: same payload for every submit (no user enumeration) except "throttled".
                (new PasswordResetCheckEmail(
                    $profile->name,
                    (int) ($profile->passwordReset['token_ttl'] ?? 3600),
                    $outcome->throttled,
                    $outcome->availableAt,
                ))->store($request);

                return new Response('', Response::HTTP_FOUND, [
                    'Location' => $this->urlGenerator->generate($afterRequestRoute),
                ]);
            }

            $this->flashMessenger->flash($request, 'success', 'reset.request.flash_sent');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $formView = $form->createView();
        $links    = [];
        // delivery code|both: the code-entry page is reachable from here (the user may already hold a code).
        if (($profile->passwordReset['delivery'] ?? 'link') !== PasswordResetDeliveryMode::Link->value) {
            $links[] = $this->pages->link('reset.request.enter_code', $this->urlGenerator->generate($profile->routes['reset_password_code']['name']));
        }
        $links[] = $this->pages->link('reset.request.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name']));
        $page    = $this->pages->create('reset_request', $profile, $formView, $links);

        $content = $this->twig->render($profile->template('reset_request'), $this->pages->context('reset_request', $page, $profile, [
            'reset_request_form' => $formView,
            'login_route'        => $profile->routes['login']['name'],
        ]));

        return new Response($content);
    }
}
