<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Completes the QR login: authenticates the desktop user after approval.
 */
final class QrLoginCompleteController
{
    public function __construct(
        private readonly QrLoginGate $gate,
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function complete(Request $request, string $id): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if (!$this->gate->isEnabled($profile->name)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        $challenge = $this->challengeManager->find($id);
        if (!$challenge instanceof QrLoginChallenge) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        if (!$this->challengeManager->verifyDesktopCookie($challenge, $request)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        if (!$this->challengeManager->verifyDesktopBinding($challenge, $request, $profile->name)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        if ($challenge->getStatus() !== QrLoginChallengeStatus::Approved) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        $userClass = $challenge->getUserClass();
        $userId    = $challenge->getUserId();
        if ($userClass === null || $userId === null) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        /** @var class-string $userClass */
        $repository = $this->entityManager->getRepository($userClass);
        $user       = $repository->findOneBy([$profile->userIdentifierField => $userId]);

        if (!$user instanceof UserInterface) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        $this->challengeManager->consume($challenge, $user, $profile->name);
        $this->security->login($user, null, $profile->firewall);

        $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate($target),
        ]);
    }

    private function redirectToLogin(string $routeName): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate($routeName),
        ]);
    }
}
