<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Controller;

use Nowo\AuthKitBundle\Mfa\Form\MfaCodeFormType;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Mfa\MfaEnrolment;
use Nowo\AuthKitBundle\Mfa\MfaLoginCompleter;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function intdiv;

/**
 * Second step of a pending sign-in (1.34): the challenge (TOTP or recovery code, optional "trust this device") and
 * the forced enrolment of a required user whose grace period is over. Only reachable with an {@see MfaPendingToken}
 * (anything else is redirected); every POST is CSRF-protected (fail closed); responses are `private, no-store` and
 * never framed.
 */
final class MfaChallengeController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly TokenStorageInterface $tokens,
        private readonly MfaManager $manager,
        private readonly MfaTrustedDevices $devices,
        private readonly MfaLoginCompleter $completer,
        private readonly MfaEnrolment $enrolment,
        private readonly AuthPageFactory $pages,
        private readonly AuthKitUrlGenerator $urls,
        private readonly RequestProfileResolver $profileResolver,
    ) {
    }

    #[Route(path: '/login/mfa', name: 'nowo_auth_kit_mfa_challenge', methods: ['GET', 'POST'])]
    public function challenge(Request $request): Response
    {
        $pending = $this->tokens->getToken();
        if (!$pending instanceof MfaPendingToken || $pending->mode() !== MfaPendingToken::MODE_CHALLENGE) {
            return $this->elsewhere($request, $pending);
        }
        $profile  = $this->manager->profileNamed($pending->profile());
        $user     = $this->user($pending);
        $lifetime = (int) ($profile->mfa['trusted_devices']['lifetime'] ?? 0);
        $form     = $this->formFactory->create(MfaCodeFormType::class, null, [
            'action'            => $this->urls->generate((string) $profile->mfa['routes']['challenge']['name']),
            'digits'            => (int) ($profile->mfa['totp']['digits'] ?? 6),
            'recovery'          => true,
            'trust_device'      => $lifetime > 0,
            'trust_device_days' => intdiv($lifetime, 86400),
        ]);
        $error = null;
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $code = MfaCodeFormType::submittedCode($form);
                if ($code === '') {
                    $error = 'mfa.error.code_required';
                } else {
                    $result = $this->manager->check($user, $code, $request->getClientIp(), 'challenge');
                    if ($result->isValid()) {
                        if ($form->has('trust_device') && $form->get('trust_device')->getData() === true) {
                            $this->devices->trust($request, $user);
                        }

                        return $this->completer->complete($request, $pending, $result->value);
                    }
                    if ($result === MfaCheck::LockedOut) {
                        return $this->completer->abort($request, $pending, 'mfa.flash.locked_out');
                    }
                    if ($pending->fail() >= (int) ($profile->mfa['max_attempts'] ?? 5)) {
                        return $this->completer->abort($request, $pending, 'mfa.flash.too_many_attempts');
                    }
                    $error = 'mfa.error.invalid_code';
                }
            }
        }

        return $this->render($profile, 'mfa_challenge', $form, $error, [
            'mfa_logout_route' => $profile->routes['logout']['name'],
        ]);
    }

    #[Route(path: '/login/mfa/setup', name: 'nowo_auth_kit_mfa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $pending = $this->tokens->getToken();
        if (!$pending instanceof MfaPendingToken || $pending->mode() !== MfaPendingToken::MODE_SETUP) {
            return $this->elsewhere($request, $pending);
        }
        $profile = $this->manager->profileNamed($pending->profile());
        $user    = $this->user($pending);
        $action  = $this->urls->generate((string) $profile->mfa['routes']['setup']['name']);
        if ($request->isMethod('POST') && $request->request->has('csrf_only')) {
            $continue = $this->csrfForms->createNamed($action, 'nowo_auth_kit_mfa_continue');
            $continue->handleRequest($request);
            if ($continue->isSubmitted() && $continue->isValid() && $this->manager->isEnabled($user)) {
                return $this->completer->complete($request, $pending, 'enrolment');
            }

            return new RedirectResponse($action, Response::HTTP_SEE_OTHER);
        }
        $secret = $this->enrolment->secret($request, $user);
        $form   = $this->formFactory->create(MfaCodeFormType::class, null, [
            'action' => $action,
            'digits' => (int) ($profile->mfa['totp']['digits'] ?? 6),
        ]);
        $error = null;
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                // @igor-ignore - Stateless service call (the second factor is stored in the database), not a mutation of worker state.
                $codes = $this->manager->enable($user, $secret, MfaCodeFormType::submittedCode($form), $request);
                if ($codes !== null) {
                    // @igor-ignore - Removes the enrolment secret from the request's own session.
                    $this->enrolment->clear($request);

                    return $this->recoveryCodes($profile, $codes, $action);
                }
                $error = 'mfa.error.invalid_code';
            }
        }

        return $this->render($profile, 'mfa_setup', $form, $error, [
            'mfa_enrolment'    => $this->enrolment->view($user, $secret),
            'mfa_logout_route' => $profile->routes['logout']['name'],
        ]);
    }

    /**
     * The recovery codes shown once after an enrolment, with the CSRF form that finishes the sign-in.
     *
     * @param list<string> $codes
     */
    private function recoveryCodes(ProfileSettings $profile, array $codes, string $action): Response
    {
        $continue = $this->csrfForms->createNamed($action, 'nowo_auth_kit_mfa_continue');
        $page     = $this->pages->create('mfa_recovery_codes', $profile);
        $content  = $this->twig->render($profile->template('mfa_recovery_codes'), $this->pages->context('mfa_recovery_codes', $page, $profile, [
            'mfa_codes'         => $codes,
            'mfa_continue_form' => $continue->createView(),
        ]));

        return self::noStore(new Response($content));
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $variables
     */
    private function render(ProfileSettings $profile, string $page, FormInterface $form, ?string $error, array $variables): Response
    {
        $view     = $form->createView();
        $authPage = $this->pages->create($page, $profile, $view, [], $error !== null ? AuthPageFactory::label($error) : null);
        $content  = $this->twig->render($profile->template($page), $this->pages->context($page, $authPage, $profile, $variables));

        return self::noStore(new Response($content, $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Not in the expected step: the other step's page, the app (completed sign-in) or the sign-in page.
     */
    private function elsewhere(Request $request, mixed $token): RedirectResponse
    {
        if ($token instanceof MfaPendingToken) {
            $profile = $this->manager->profileNamed($token->profile());

            return new RedirectResponse($this->urls->generate((string) $profile->mfa['routes'][$token->mode()]['name']));
        }
        $profile = $this->profileResolver->resolve($request);
        if ($token?->getUser() instanceof UserInterface) {
            return new RedirectResponse($this->completer->afterLogin($profile));
        }

        return new RedirectResponse($this->urls->generate($profile->routes['login']['name']));
    }

    private function user(MfaPendingToken $pending): UserInterface
    {
        return $pending->getUser() ?? throw new AccessDeniedHttpException('A pending sign-in has no user.');
    }

    public static function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
