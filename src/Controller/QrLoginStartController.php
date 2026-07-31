<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\QrLogin\QrLoginRateLimiter;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates a new QR login challenge and redirects to the show page.
 */
final class QrLoginStartController
{
    public function __construct(
        private readonly QrLoginGate $gate,
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly QrLoginRateLimiter $rateLimiter,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function start(Request $request): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if (!$this->gate->isEnabled($profile->name)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $rateLimit  = (int) ($profile->qrLogin['create_rate_limit'] ?? 5);
        $rateWindow = (int) ($profile->qrLogin['create_rate_window'] ?? 600);

        if (!$this->rateLimiter->allowCreate($request->getClientIp() ?? 'unknown', $rateLimit, $rateWindow)) {
            return new Response('Too many requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result    = $this->challengeManager->create($request, $profile->name);
        $challenge = $result['challenge'];
        $ttl       = (int) ($profile->qrLogin['challenge_ttl'] ?? 90);
        $cookie    = $this->challengeManager->createDesktopCookie($result['cookie_value'], $ttl + 60);

        $request->getSession()->set(
            'ak_qr_approve_' . $challenge->getId(),
            $result['approve_token'],
        );

        $response = new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate(
                $profile->routes['qr_login_show']['name'],
                ['id' => $challenge->getId()],
            ),
        ]);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
