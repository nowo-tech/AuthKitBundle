<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\Form\MfaCodeFormType;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaStatus;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function array_column;

/**
 * The signed-in user's second factor (1.34): status, enrolment (QR + confirm), disable, new recovery codes and trusted
 * devices. Disable and new codes ask for a current code (step-up). Refused while impersonating (an administrator
 * using `switch_user` can never read, disable or regenerate the user's factor). Every POST is CSRF-protected (fail
 * closed); responses are `private, no-store` and never framed; redirects are path-only.
 */
final class MfaAccountController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly TokenStorageInterface $tokens,
        private readonly MfaManager $manager,
        private readonly MfaEnrolment $enrolment,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
    ) {
    }

    #[Route(path: '/account/security/mfa', name: 'nowo_auth_kit_mfa_account', methods: ['GET'])]
    public function account(Request $request): Response
    {
        [$user, $profile, $status] = $this->context();
        $variables                 = ['mfa_status' => $status, 'mfa_profile' => $profile->name];
        if (!$status->enabled) {
            $secret = $this->enrolment->secret($request, $user);
            $variables += [
                'mfa_enrolment'   => $this->enrolment->view($user, $secret),
                'mfa_enable_form' => $this->codeForm($profile, 'enable')->createView(),
            ];
        } else {
            $variables += [
                'mfa_disable_form' => $this->codeForm($profile, 'disable')->createView(),
                'mfa_codes_form'   => $this->codeForm($profile, 'recovery_codes')->createView(),
                'mfa_device_forms' => $this->deviceForms($profile, $status),
            ];
        }

        return $this->render('@NowoAuthKitBundle/mfa/account.html.twig', $variables);
    }

    #[Route(path: '/account/security/mfa/enable', name: 'nowo_auth_kit_mfa_enable', methods: ['POST'])]
    public function enable(Request $request): Response
    {
        [$user, $profile] = $this->context();
        $form             = $this->submitted($request, $this->codeForm($profile, 'enable'));
        if ($form instanceof FormInterface) {
            // @igor-ignore - Stateless service call (the second factor is stored in the database), not a mutation of worker state.
            $codes = $this->manager->enable($user, $this->enrolment->secret($request, $user), MfaCodeFormType::submittedCode($form), $request);
            if ($codes !== null) {
                // @igor-ignore - Removes the enrolment secret from the request's own session.
                $this->enrolment->clear($request);
                $this->flash->flash($request, 'success', 'mfa.flash.enabled');

                return $this->codesPage($profile, $codes);
            }
        }
        $this->flash->flash($request, 'error', 'mfa.error.invalid_code');

        return $this->back($profile);
    }

    #[Route(path: '/account/security/mfa/disable', name: 'nowo_auth_kit_mfa_disable', methods: ['POST'])]
    public function disable(Request $request): RedirectResponse
    {
        [$user, $profile] = $this->context();
        if ($this->stepUp($request, $user, $profile, 'disable')) {
            // @igor-ignore - Stateless service call (database write), not a mutation of worker state.
            $this->manager->disable($user, $request);
            $this->flash->flash($request, 'success', 'mfa.flash.disabled');
        }

        return $this->back($profile);
    }

    #[Route(path: '/account/security/mfa/recovery-codes', name: 'nowo_auth_kit_mfa_recovery_codes', methods: ['POST'])]
    public function recoveryCodes(Request $request): Response
    {
        [$user, $profile] = $this->context();
        if ($this->stepUp($request, $user, $profile, 'recovery_codes')) {
            $this->flash->flash($request, 'success', 'mfa.flash.codes_regenerated');

            return $this->codesPage($profile, $this->manager->regenerateRecoveryCodes($user));
        }

        return $this->back($profile);
    }

    #[Route(path: '/account/security/mfa/devices/{device}/forget', name: 'nowo_auth_kit_mfa_forget_device', requirements: ['device' => '[0-9a-f]{32}|all'], methods: ['POST'])]
    public function forgetDevice(Request $request, string $device): RedirectResponse
    {
        [$user, $profile] = $this->context();
        $form             = $this->csrfForms->createNamed($request->getPathInfo(), 'nowo_auth_kit_mfa_forget.' . $profile->name . '.' . $device);
        if (!$this->submitted($request, $form) instanceof FormInterface) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $count = $this->manager->forgetDevices($user, 'user', $device === 'all' ? null : $device, $request);
        $this->flash->flash($request, $count > 0 ? 'success' : 'warning', $count > 0 ? 'mfa.flash.device_forgotten' : 'mfa.flash.device_unknown');

        return $this->back($profile);
    }

    /**
     * @return array{0: UserInterface, 1: ProfileSettings, 2: MfaStatus}
     */
    private function context(): array
    {
        $token = $this->tokens->getToken();
        $user  = $token?->getUser();
        if (!$user instanceof UserInterface || $token instanceof MfaPendingToken) {
            throw new AccessDeniedException('Sign in to manage two-factor authentication.');
        }
        if ($token instanceof SwitchUserToken) {
            throw new AccessDeniedHttpException('Two-factor authentication cannot be managed while impersonating.');
        }
        $status = $this->manager->status($user);
        if (!$status instanceof MfaStatus) {
            throw new NotFoundHttpException('Two-factor authentication is not enabled for this account type.');
        }

        return [$user, $this->manager->profileNamed($status->profile), $status];
    }

    /**
     * Checks the step-up code of a sensitive action (flash on failure).
     */
    private function stepUp(Request $request, UserInterface $user, ProfileSettings $profile, string $action): bool
    {
        $form = $this->submitted($request, $this->codeForm($profile, $action));
        if (!$form instanceof FormInterface) {
            $this->flash->flash($request, 'error', 'mfa.error.invalid_code');

            return false;
        }
        $result = $this->manager->check($user, MfaCodeFormType::submittedCode($form), $request->getClientIp(), 'step_up');
        if (!$result->isValid()) {
            $this->flash->flash($request, 'error', $result === MfaCheck::LockedOut ? 'mfa.flash.locked_out' : 'mfa.error.invalid_code');
        }

        return $result->isValid();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function codeForm(ProfileSettings $profile, string $action): FormInterface
    {
        return $this->formFactory->createNamed('mfa_' . $action, MfaCodeFormType::class, null, [
            'action'        => $this->urls->generate((string) $profile->mfa['routes'][$action]['name']),
            'digits'        => (int) ($profile->mfa['totp']['digits'] ?? 6),
            'recovery'      => $action !== 'enable',
            'csrf_token_id' => 'nowo_auth_kit_mfa.' . $action . '.' . $profile->name,
        ]);
    }

    /**
     * @return array<string, FormView> forget forms by device public id (plus `all`)
     */
    private function deviceForms(ProfileSettings $profile, MfaStatus $status): array
    {
        $forms = [];
        foreach ([...array_column($status->trustedDevices, 'public_id'), 'all'] as $device) {
            $forms[$device] = $this->csrfForms->createNamed(
                $this->urls->generate((string) $profile->mfa['routes']['forget_device']['name'], ['device' => $device]),
                'nowo_auth_kit_mfa_forget.' . $profile->name . '.' . $device,
            )->createView();
        }

        return $forms;
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

    /**
     * @param list<string> $codes
     */
    private function codesPage(ProfileSettings $profile, array $codes): Response
    {
        return $this->render('@NowoAuthKitBundle/mfa/account_codes.html.twig', [
            'mfa_codes'       => $codes,
            'mfa_profile'     => $profile->name,
            'mfa_account_url' => $this->urls->generate((string) $profile->mfa['routes']['account']['name']),
        ]);
    }

    private function back(ProfileSettings $profile): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate((string) $profile->mfa['routes']['account']['name']), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return MfaChallengeController::noStore(new Response($this->twig->render($template, $context)));
    }
}
