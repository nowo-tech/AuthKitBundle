<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Form\QrLoginRetryType;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrCodeGeneratorInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly ?FormFactoryInterface $formFactory = null,
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
    ) {
    }

    #[Route(path: '/login/qr/{id}', name: 'nowo_auth_kit_qr_login_show', requirements: ['id' => '[0-9a-f\\-]{36}'], methods: ['GET'])]
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

        if (!$this->challengeManager->verifyDesktopCookie($challenge, $request, $profile->name)) {
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

        $startUrl     = $this->urlGenerator->generate($profile->routes['qr_login_start']['name']);
        $statusUrl    = $this->urlGenerator->generate($profile->routes['qr_login_status']['name'], ['id' => $challenge->getId()]);
        $completeUrl  = $this->urlGenerator->generate($profile->routes['qr_login_complete']['name'], ['id' => $challenge->getId()]);
        $pollInterval = (int) ($profile->qrLogin['poll_interval_ms'] ?? 1500);
        $controller   = $profile->webUi['qr_login']['stimulus_controller'] ?? null;
        $retryForm    = ($this->formFactory ?? Forms::createFormFactory())
            ->createNamed('', QrLoginRetryType::class, null, ['action' => $startUrl])
            ->createView();
        // The Stimulus controller sits on the auth panel: the challenge (fields) and the continue/retry targets
        // (actions) are both its descendants.
        $page = $this->pages->create('qr_login_show', $profile, $retryForm, [
            $this->pages->link('qr_login.show.back_to_login', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ], attributes: $this->pages->stimulusAttributes($controller, [
            'status-url'    => $statusUrl,
            'complete-url'  => $completeUrl,
            'start-url'     => $startUrl,
            'poll-interval' => $pollInterval,
            'expires-at'    => $challenge->getExpiresAt()->getTimestamp(),
        ]));

        $content = $this->twig->render($profile->template('qr_login_show'), $this->pages->context('qr_login_show', $page, $profile, [
            'status_url'             => $statusUrl,
            'complete_url'           => $completeUrl,
            'start_url'              => $startUrl,
            'qr_stimulus_controller' => $controller,
            'challenge_id'           => $challenge->getId(),
            'public_code'            => $challenge->getPublicCode(),
            'qr_data_uri'            => $qrDataUri,
            'qr_payload'             => $qrPayload,
            'expires_at'             => $challenge->getExpiresAt()->getTimestamp(),
            'expires_in'             => $this->challengeManager->expiresIn($challenge),
            'status_route'           => $profile->routes['qr_login_status']['name'],
            'complete_route'         => $profile->routes['qr_login_complete']['name'],
            'poll_interval_ms'       => $pollInterval,
            'login_route'            => $profile->routes['login']['name'],
        ]));

        return new Response($content);
    }
}
