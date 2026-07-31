<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns challenge status as JSON for desktop polling ({status, expires_in} only — no PII).
 */
final class QrLoginStatusController
{
    public function __construct(
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $this->profileResolver->resolve($request);

        $challenge = $this->challengeManager->find($id);
        if (!$challenge instanceof QrLoginChallenge) {
            return new JsonResponse(['status' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->challengeManager->verifyDesktopCookie($challenge, $request)) {
            return new JsonResponse(['status' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $this->challengeManager->isExpiredOrInvalid($challenge);

        $expiresIn = max(0, $challenge->getExpiresAt()->getTimestamp() - time());

        return new JsonResponse([
            'status'     => $challenge->getStatus()->value,
            'expires_in' => $expiresIn,
        ]);
    }
}
