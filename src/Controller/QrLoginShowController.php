<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrCodeGeneratorInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function is_string;

/**
 * Renders the QR challenge page (QR code + short code + status poller).
 */
final class QrLoginShowController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly QrLoginGate $gate,
        private readonly QrLoginChallengeManager $challengeManager,
        private readonly QrCodeGeneratorInterface $qrCodeGenerator,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    public function show(Request $request, string $id): Response
    {
        $profile = $this->profileResolver->resolve($request);

        if (!$this->gate->isEnabled($profile->name)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        $challenge = $this->challengeManager->find($id);
        if (!$challenge instanceof QrLoginChallenge) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        if (!$this->challengeManager->verifyDesktopCookie($challenge, $request)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['login']['name']),
            ]);
        }

        if ($this->challengeManager->isExpiredOrInvalid($challenge)) {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['qr_login_start']['name']),
            ]);
        }

        $approveToken = $request->getSession()->get('ak_qr_approve_' . $challenge->getId());
        if (!is_string($approveToken) || $approveToken === '') {
            return new Response('', Response::HTTP_FOUND, [
                'Location' => $this->urlGenerator->generate($profile->routes['qr_login_start']['name']),
            ]);
        }

        $approveUrl = $this->urlGenerator->generate(
            $profile->routes['qr_login_approve']['name'],
            ['id' => $challenge->getId(), 't' => $approveToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $qrPayload = $approveUrl;
        $qrDataUri = $this->qrCodeGenerator->generateDataUri($qrPayload);

        $content = $this->twig->render('@NowoAuthKitBundle/security/qr_login_show.html.twig', [
            'challenge_id'     => $challenge->getId(),
            'public_code'      => $challenge->getPublicCode(),
            'qr_data_uri'      => $qrDataUri,
            'qr_payload'       => $qrPayload,
            'expires_at'       => $challenge->getExpiresAt()->getTimestamp(),
            'status_route'     => $profile->routes['qr_login_status']['name'],
            'complete_route'   => $profile->routes['qr_login_complete']['name'],
            'poll_interval_ms' => (int) ($profile->qrLogin['poll_interval_ms'] ?? 1500),
            'login_route'      => $profile->routes['login']['name'],
            'layout_template'  => $profile->templates['layout'],
        ]);

        return new Response($content);
    }
}
