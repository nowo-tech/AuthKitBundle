<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Handles user registration according to the configured registration mode.
 */
final class RegisterController
{
    /**
     * @param array{layout: string, login: string, register: string} $templates
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly RegistrationGate $registrationGate,
        private readonly UserRegistrar $userRegistrar,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly array $templates,
        private readonly array $routes,
        private readonly string $firewall,
        private readonly ?string $loginSuccessRoute,
    ) {
    }

    public function register(Request $request): Response
    {
        if ($this->tokenStorage->getToken()?->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            $target = $this->loginSuccessRoute ?? $this->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        if (!$this->registrationGate->isRegistrationAllowed()) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($this->routes['login']['name']),
            ]);
        }

        $form = $this->formFactory->create(RegistrationFormType::class);
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $user = $this->userRegistrar->register($data);

            $token = new UsernamePasswordToken($user, $this->firewall, $user->getRoles());
            $this->tokenStorage->setToken($token);
            $request->getSession()->set('_security_' . $this->firewall, serialize($token));

            $loginEvent = new InteractiveLoginEvent($request, $token);
            $this->eventDispatcher->dispatch($loginEvent, SecurityEvents::INTERACTIVE_LOGIN);

            $target = $this->loginSuccessRoute ?? $this->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        $content = $this->twig->render($this->templates['register'], [
            'registration_form' => $form->createView(),
            'login_route'       => $this->routes['login']['name'],
            'layout_template'   => $this->templates['layout'],
        ]);

        return new Response($content);
    }
}
