<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Handles user registration according to the configured registration mode.
 */
final class RegisterController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly RegistrationGate $registrationGate,
        private readonly UserRegistrar $userRegistrar,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function register(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        if (!$this->registrationGate->isRegistrationAllowed($profile->name)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $form = $this->formFactory->create(RegistrationFormType::class, null, [
            'profile' => $profile->name,
        ]);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $user = $this->userRegistrar->register($data, $profile->name);

            $session = $request->getSession();
            $session->migrate(true);

            $token = new UsernamePasswordToken($user, $profile->firewall, $user->getRoles());
            $this->tokenStorage->setToken($token);
            $session->set('_security_' . $profile->firewall, serialize($token));

            $loginEvent = new InteractiveLoginEvent($request, $token);
            $this->eventDispatcher->dispatch($loginEvent, SecurityEvents::INTERACTIVE_LOGIN);

            $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($target),
            ]);
        }

        $content = $this->twig->render($profile->templates['register'], [
            'registration_form' => $form->createView(),
            'login_route'       => $profile->routes['login']['name'],
            'layout_template'   => $profile->templates['layout'],
        ]);

        return new Response($content);
    }
}
