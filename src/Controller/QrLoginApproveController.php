<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginApproveMode;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\QrLogin\QrLoginRateLimiter;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginUserResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * Handles QR login approval from the phone (GET renders form, POST approves).
 */
final class QrLoginApproveController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly QrLoginGate $gate,
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly QrLoginUserResolver $userResolver,
        private readonly QrLoginRateLimiter $rateLimiter,
        private readonly QrLoginStepUpInterface $stepUp,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function approve(Request $request, string $id): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if (!$this->gate->isEnabled($profile->name)) {
            return new Response('QR login disabled', Response::HTTP_FORBIDDEN);
        }

        $challenge = $this->challengeManager->find($id);
        if (!$challenge instanceof QrLoginChallenge) {
            return new Response('Challenge not found', Response::HTTP_NOT_FOUND);
        }

        if ($this->challengeManager->isExpiredOrInvalid($challenge)) {
            return new Response('Challenge expired', Response::HTTP_GONE);
        }

        if ($challenge->getStatus() !== QrLoginChallengeStatus::Pending) {
            return new Response('Challenge already resolved', Response::HTTP_CONFLICT);
        }

        $token = (string) ($request->query->get('t') ?? $request->request->get('t') ?? '');
        if ($token === '' || !$this->challengeManager->verifyApproveToken($challenge, $token)) {
            return new Response('Invalid approve token', Response::HTTP_FORBIDDEN);
        }

        $approveLimit = (int) ($profile->qrLogin['approve_rate_limit'] ?? 5);
        if (!$this->rateLimiter->allowApprove($challenge->getId(), $approveLimit)) {
            return new Response('Too many attempts', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new Response('Authentication required', Response::HTTP_UNAUTHORIZED);
        }

        $phoneResult = $this->userResolver->validatePhone($user, $profile->name);
        if (!$phoneResult['valid']) {
            return new Response('Phone not verified', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('GET')) {
            $content = $this->twig->render('@NowoAuthKitBundle/security/qr_login_approve.html.twig', [
                'challenge_id'    => $challenge->getId(),
                'desktop_label'   => $challenge->getDesktopUaLabel(),
                'phone_hint'      => $phoneResult['phone_hint'],
                'approve_token'   => $token,
                'deny_route'      => $profile->routes['qr_login_deny']['name'],
                'layout_template' => $profile->templates['layout'],
            ]);

            return new Response($content);
        }

        $approveMode = QrLoginApproveMode::from($profile->qrLogin['approve_requires'] ?? 'session');
        if ($approveMode === QrLoginApproveMode::SessionStepUp) {
            try {
                $this->stepUp->assertUnlocked($request);
            } catch (AccessDeniedException) {
                return new Response('Step-up verification failed', Response::HTTP_FORBIDDEN);
            }
        }

        $this->challengeManager->approve($challenge, $user, $phoneResult['phone_hint'], $profile->name);

        return new Response('Approved. You can close this window and continue on your computer.');
    }
}
