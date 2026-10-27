<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeConflictException;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_a;

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
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
    ) {
    }

    #[Route(path: '/login/qr/{id}/complete', name: 'nowo_auth_kit_qr_login_complete', requirements: ['id' => '[0-9a-f\\-]{36}'], methods: ['GET'])]
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

        if (!$this->challengeManager->verifyDesktopCookie($challenge, $request, $profile->name)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        if (!$this->challengeManager->verifyDesktopBinding($challenge, $request, $profile->name)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        if ($challenge->getStatus() !== QrLoginChallengeStatus::Approved) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        // An approved challenge must still be completed within its lifetime.
        if ($challenge->isExpired()) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        $userClass = $challenge->getUserClass();
        $userId    = $challenge->getUserId();
        // SEC-004 L2: the approving account must belong to this profile's user class.
        if ($userClass === null || $userId === null || !is_a($userClass, $profile->userClass, true)) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        // Exact stored identifier (no collation look-alike), looked up in the profile's user class.
        $user = $this->identifierMatcher->find(
            $this->entityManager->getRepository($profile->userClass),
            $profile->userIdentifierField,
            $userId,
        );

        if (!$user instanceof UserInterface) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

        try {
            $this->challengeManager->consume($challenge, $user, $profile->name);
        } catch (QrLoginChallengeConflictException) {
            return $this->redirectToLogin($profile->routes['login']['name']);
        }

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
