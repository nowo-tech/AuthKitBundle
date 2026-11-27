<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaLoginCompleter;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Webauthn\Form\WebauthnCredentialFormType;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A passkey as the second step of a pending sign-in (2.4.0, `webauthn.mfa` with `mfa.enabled`): the options list the
 * pending user's passkeys only, and the assertion must come from one of them. Each assertion uses one attempt of the
 * pending sign-in (`mfa.max_attempts`, shared with the code form); too many end the sign-in. Only reachable with a
 * challenge-mode {@see MfaPendingToken}.
 */
final class WebauthnMfaController
{
    public function __construct(
        private readonly WebauthnManager $passkeys,
        private readonly MfaManager $mfa,
        private readonly MfaLoginCompleter $completer,
        private readonly AuthKitAttemptLimiter $limiter,
        private readonly FormFactoryInterface $forms,
        private readonly TokenStorageInterface $tokens,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    #[Route(path: '/login/mfa/webauthn/options', name: 'nowo_auth_kit_webauthn_mfa_options', methods: ['GET'])]
    public function options(Request $request): JsonResponse
    {
        $pending = $this->pending();
        $user    = $pending?->getUser();
        if (!$pending instanceof MfaPendingToken || !$user instanceof UserInterface || !$this->passkeys->hasSecondFactor($user)) {
            return WebauthnLoginController::json(['error' => 'mfa_required'], Response::HTTP_UNAUTHORIZED);
        }
        try {
            return WebauthnLoginController::json($this->passkeys->mfaOptions($user, $request));
        } catch (WebauthnFailure $failure) {
            return WebauthnLoginController::refused($failure);
        }
    }

    #[Route(path: '/login/mfa/webauthn/assert', name: 'nowo_auth_kit_webauthn_mfa_assert', methods: ['POST'])]
    public function assert(Request $request): RedirectResponse
    {
        $pending = $this->pending();
        $user    = $pending?->getUser();
        if (!$pending instanceof MfaPendingToken || !$user instanceof UserInterface) {
            return new RedirectResponse($this->urls->generate($this->profileResolver->resolve($request)->routes['login']['name']), Response::HTTP_SEE_OTHER);
        }
        $profile   = $this->mfa->profileNamed($pending->profile());
        $challenge = new RedirectResponse($this->urls->generate((string) $profile->mfa['routes']['challenge']['name']), Response::HTTP_SEE_OTHER);
        $form      = self::form($this->forms, $profile, $this->urls);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || WebauthnCredentialFormType::credential($form) === '') {
            $this->flash->flash($request, 'error', 'webauthn.flash.failed');

            return $challenge;
        }
        // The same attempt budget as the code form of this pending sign-in (DD-M1).
        if (!$this->limiter->consume('mfa:pending:' . $pending->id(), (int) ($profile->mfa['max_attempts'] ?? 5), (int) ($profile->mfa['pending_lifetime'] ?? 300))) {
            return $this->completer->abort($request, $pending, 'mfa.flash.too_many_attempts');
        }
        try {
            $this->passkeys->verifyMfa($user, $request, WebauthnCredentialFormType::credential($form));
        } catch (WebauthnFailure $failure) {
            $this->flash->flash($request, 'error', WebauthnLoginController::flashFor($failure));

            return $challenge;
        }

        return $this->completer->complete($request, $pending, 'webauthn');
    }

    /**
     * The passkey form of the challenge page (hidden credential, CSRF token `nowo_auth_kit_webauthn_mfa.<profile>`).
     *
     * @return FormInterface<mixed>
     */
    public static function form(FormFactoryInterface $forms, ProfileSettings $profile, AuthKitUrlGenerator $urls): FormInterface
    {
        return $forms->createNamed('webauthn_mfa', WebauthnCredentialFormType::class, null, [
            'action'        => $urls->generate((string) $profile->webauthn['routes']['mfa_assert']['name']),
            'csrf_token_id' => 'nowo_auth_kit_webauthn_mfa.' . $profile->name,
        ]);
    }

    private function pending(): ?MfaPendingToken
    {
        $token = $this->tokens->getToken();

        return $token instanceof MfaPendingToken && $token->mode() === MfaPendingToken::MODE_CHALLENGE ? $token : null;
    }
}
