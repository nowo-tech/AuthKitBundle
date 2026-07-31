<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Handles QR login denial from the phone.
 */
final class QrLoginDenyController
{
    public function __construct(
        private readonly QrLoginGate $gate,
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function deny(Request $request, string $id): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if (!$this->gate->isEnabled($profile->name)) {
            return new Response('QR login disabled', Response::HTTP_FORBIDDEN);
        }

        $challenge = $this->challengeManager->find($id);
        if (!$challenge instanceof QrLoginChallenge) {
            return new Response('Challenge not found', Response::HTTP_NOT_FOUND);
        }

        if ($challenge->getStatus() !== QrLoginChallengeStatus::Pending) {
            return new Response('Challenge already resolved', Response::HTTP_CONFLICT);
        }

        $token = (string) ($request->query->get('t') ?? $request->request->get('t') ?? '');
        if ($token === '' || !$this->challengeManager->verifyApproveToken($challenge, $token)) {
            return new Response('Invalid token', Response::HTTP_FORBIDDEN);
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new Response('Authentication required', Response::HTTP_UNAUTHORIZED);
        }

        $this->challengeManager->deny($challenge, $profile->name);

        return new Response('Login denied. You can close this window.');
    }
}
