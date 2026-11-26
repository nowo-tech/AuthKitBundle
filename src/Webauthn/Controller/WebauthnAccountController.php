<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\Controller\MfaChallengeController;
use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Webauthn\Form\WebauthnCredentialFormType;
use Nowo\AuthKitBundle\Webauthn\Form\WebauthnRenameFormType;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\AuthKitBundle\Webauthn\WebauthnRemoval;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function ceil;
use function count;
use function is_int;
use function is_string;

/**
 * The signed-in user's passkeys (2.4.0): list, add (options + registration form), rename and remove. Adding, renaming
 * and removing need a recent full sign-in (`webauthn.recent_sign_in`; a hijacked long-lived session cannot bind the
 * attacker's authenticator or remove the owner's). Refused while impersonating and for remember-me sessions. Removing
 * the last passkey is refused while a second factor is required and TOTP is off. Every POST is a CSRF-protected form;
 * an unknown passkey and another account's passkey get the same answer. Responses are `private, no-store`.
 */
final class WebauthnAccountController
{
    /** Flash of a refused registration by reason (2.6.0: an invalid relying party asks for the password). */
    private const REGISTER_FLASHES = [
        WebauthnFailure::LIMIT         => 'webauthn.flash.limit',
        WebauthnFailure::RELYING_PARTY => 'webauthn.flash.unavailable',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $forms,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly TokenStorageInterface $tokens,
        private readonly WebauthnManager $passkeys,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ClockInterface $clock,
        private readonly ?MfaManager $mfa = null,
    ) {
    }

    #[Route(path: '/account/webauthn', name: 'nowo_auth_kit_webauthn_account', methods: ['GET'])]
    public function account(Request $request): Response
    {
        [$user, $profile] = $this->context();
        $passkeys         = $this->passkeys->passkeys($user);
        $variables        = [
            'webauthn_profile'              => $profile->name,
            'webauthn_passkeys'             => $passkeys,
            'webauthn_max'                  => (int) ($profile->webauthn['max_credentials'] ?? 10),
            'nowo_auth_kit_webauthn_assets' => true,
            'webauthn_signal'               => $this->signal($user, $request),
        ];
        if (!$this->isRecent($request, $profile)) {
            $variables += ['webauthn_sign_in_again' => (int) ceil($this->recentWindow($profile) / 60), 'webauthn_logout_route' => $profile->routes['logout']['name']];
        } else {
            $renames = [];
            $deletes = [];
            foreach ($passkeys as $passkey) {
                $renames[$passkey->publicId] = $this->renameForm($profile, $passkey->publicId, $passkey->label)->createView();
                $deletes[$passkey->publicId] = $this->deleteForm($profile, $passkey->publicId)->createView();
            }
            $variables += [
                'webauthn_register_form'        => count($passkeys) < $variables['webauthn_max'] ? $this->registerForm($profile)->createView() : null,
                'webauthn_register_options_url' => $this->urls->generate((string) $profile->webauthn['routes']['register_options']['name']),
                'webauthn_rename_forms'         => $renames,
                'webauthn_delete_forms'         => $deletes,
            ];
        }

        return $this->render($profile->template('webauthn_account'), $variables);
    }

    #[Route(path: '/account/webauthn/register/options', name: 'nowo_auth_kit_webauthn_register_options', methods: ['GET'])]
    public function registerOptions(Request $request): JsonResponse
    {
        [$user, $profile] = $this->context();
        if (!$this->isRecent($request, $profile)) {
            return WebauthnLoginController::json(['error' => 'sign_in_again'], Response::HTTP_FORBIDDEN);
        }
        try {
            return WebauthnLoginController::json($this->passkeys->registrationOptions($user, $request));
        } catch (WebauthnFailure $failure) {
            return match ($failure->reason) {
                // 2.6.0: passkeys are disabled (invalid relying party); the script shows the "unavailable" state.
                WebauthnFailure::RELYING_PARTY => WebauthnLoginController::refused($failure),
                default                        => WebauthnLoginController::json(['error' => $failure->reason], $failure->reason === WebauthnFailure::RATE_LIMITED ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT),
            };
        }
    }

    #[Route(path: '/account/webauthn/register', name: 'nowo_auth_kit_webauthn_register', methods: ['POST'])]
    public function register(Request $request): RedirectResponse
    {
        [$user, $profile] = $this->context();
        if (!$this->isRecent($request, $profile)) {
            return $this->back($profile);
        }
        $form = $this->submitted($request, $this->registerForm($profile));
        if (!$form instanceof FormInterface || WebauthnCredentialFormType::credential($form) === '') {
            $this->flash->flash($request, 'error', 'webauthn.flash.register_failed');

            return $this->back($profile);
        }
        try {
            // @igor-ignore - Stateless service call (the passkey is stored in the database), not a mutation of worker state.
            $this->passkeys->register($user, $request, WebauthnCredentialFormType::credential($form), WebauthnCredentialFormType::label($form));
        } catch (WebauthnFailure $failure) {
            $this->flash->flash($request, 'error', self::REGISTER_FLASHES[$failure->reason] ?? 'webauthn.flash.register_failed');

            return $this->back($profile);
        }
        $this->flash->flash($request, 'success', 'webauthn.flash.added');

        return $this->back($profile);
    }

    #[Route(path: '/account/webauthn/{id}/rename', name: 'nowo_auth_kit_webauthn_rename', requirements: ['id' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function rename(Request $request, string $id): RedirectResponse
    {
        [$user, $profile] = $this->context();
        if (!$this->isRecent($request, $profile)) {
            return $this->back($profile);
        }
        $form  = $this->submitted($request, $this->renameForm($profile, $id, null));
        $label = $form?->get('label')->getData();
        if (!$form instanceof FormInterface || !is_string($label)) {
            throw new AccessDeniedHttpException('Invalid form.');
        }
        $renamed = $this->passkeys->rename($user, $id, $label);
        $this->flash->flash($request, $renamed ? 'success' : 'warning', $renamed ? 'webauthn.flash.renamed' : 'webauthn.flash.unknown');

        return $this->back($profile);
    }

    #[Route(path: '/account/webauthn/{id}/delete', name: 'nowo_auth_kit_webauthn_delete', requirements: ['id' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function delete(Request $request, string $id): RedirectResponse
    {
        [$user, $profile] = $this->context();
        if (!$this->isRecent($request, $profile)) {
            return $this->back($profile);
        }
        if (!$this->submitted($request, $this->deleteForm($profile, $id)) instanceof FormInterface) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        // @igor-ignore - Stateless service call (database write), not a mutation of worker state.
        $result = $this->passkeys->remove($user, $id, $this->keepLastPasskey($user, $profile));
        $this->flash->flash($request, match ($result) {
            WebauthnRemoval::Removed    => 'success',
            WebauthnRemoval::LastFactor => 'error',
            WebauthnRemoval::Unknown    => 'warning',
        }, 'webauthn.flash.' . $result->value);

        return $this->back($profile);
    }

    /**
     * A required second factor must keep one factor: with TOTP off, the last passkey stays (the last-factor guard).
     */
    /**
     * The Signal API data of the page, or null when the relying party is invalid (2.6.0: passkeys are disabled; the page
     * still lists, renames and removes them).
     *
     * @return array{rpId: string, userId: string, allAcceptedCredentialIds: list<string>}|null
     */
    private function signal(UserInterface $user, Request $request): ?array
    {
        try {
            return $this->passkeys->acceptedCredentials($user, $request);
        } catch (WebauthnFailure) {
            return null;
        }
    }

    private function keepLastPasskey(UserInterface $user, ProfileSettings $profile): bool
    {
        return $profile->webauthnMfa() && $this->mfa instanceof MfaManager
            && $this->mfa->requirement($user) === MfaRequirement::Required && !$this->mfa->isEnabled($user);
    }

    /**
     * @return array{0: UserInterface, 1: ProfileSettings}
     */
    private function context(): array
    {
        $token = $this->tokens->getToken();
        $user  = $token?->getUser();
        if (!$user instanceof UserInterface || $token instanceof MfaPendingToken) {
            throw new AccessDeniedException('Sign in to manage your passkeys.');
        }
        if ($token instanceof SwitchUserToken) {
            throw new AccessDeniedHttpException('Passkeys cannot be managed while impersonating.');
        }
        if (!$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('Sign in again to manage your passkeys.');
        }
        $profile = $this->passkeys->profileFor($user) ?? throw new NotFoundHttpException('Passkeys are not enabled for this account type.');

        return [$user, $profile];
    }

    private function isRecent(Request $request, ProfileSettings $profile): bool
    {
        $at = $request->hasSession() ? $request->getSession()->get(MfaEnrolment::SIGNED_IN_AT) : null;

        return is_int($at) && $this->clock->now()->getTimestamp() - $at <= $this->recentWindow($profile);
    }

    private function recentWindow(ProfileSettings $profile): int
    {
        $window = $profile->webauthn['recent_sign_in'] ?? 900;

        return is_int($window) ? $window : 900;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function registerForm(ProfileSettings $profile): FormInterface
    {
        return $this->forms->createNamed('webauthn_register', WebauthnCredentialFormType::class, null, [
            'action'        => $this->urls->generate((string) $profile->webauthn['routes']['register']['name']),
            'csrf_token_id' => 'nowo_auth_kit_webauthn_register.' . $profile->name,
            'label_field'   => true,
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function renameForm(ProfileSettings $profile, string $id, ?string $label): FormInterface
    {
        return $this->forms->createNamed('webauthn_rename_' . $id, WebauthnRenameFormType::class, $label !== null ? ['label' => $label] : null, [
            'action'        => $this->urls->generate((string) $profile->webauthn['routes']['rename']['name'], ['id' => $id]),
            'csrf_token_id' => 'nowo_auth_kit_webauthn_rename.' . $profile->name . '.' . $id,
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(ProfileSettings $profile, string $id): FormInterface
    {
        return $this->csrfForms->createNamed(
            $this->urls->generate((string) $profile->webauthn['routes']['delete']['name'], ['id' => $id]),
            'nowo_auth_kit_webauthn_delete.' . $profile->name . '.' . $id,
        );
    }

    /**
     * The submitted, valid form (CSRF token checked), or null.
     *
     * @param FormInterface<mixed> $form
     */
    private function submitted(Request $request, FormInterface $form): ?FormInterface
    {
        $form->handleRequest($request);

        return $form->isSubmitted() && $form->isValid() ? $form : null;
    }

    private function back(ProfileSettings $profile): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate((string) $profile->webauthn['routes']['account']['name']), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return MfaChallengeController::noStore(new Response($this->twig->render($template, $context)));
    }
}
