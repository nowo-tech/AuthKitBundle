<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Controller;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\Controller\MfaChallengeController;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Form\WebauthnCredentialFormType;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Passwordless sign-in with a passkey (2.4.0, `webauthn.passwordless`): the options of a discoverable-credential
 * ceremony (JSON, also used by the conditional UI of the login field), and the assertion posted by the login page's
 * passkey form. Every refusal — unknown credential, another account's credential, a wrong origin, an expired or reused
 * challenge, too many attempts — answers the same flash and redirect (deny by default, no account enumeration). A
 * passkey sign-in is the first factor `webauthn` (MFA: exempt by default, the passkey verified the user).
 */
final class WebauthnLoginController
{
    public function __construct(
        private readonly WebauthnManager $passkeys,
        private readonly FormFactoryInterface $forms,
        private readonly TokenStorageInterface $tokens,
        private readonly Security $security,
        private readonly ProgrammaticLoginAuthenticator $loginAuthenticator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly AuthKitUrlGenerator $urls,
        private readonly AuthKitFlashMessenger $flash,
    ) {
    }

    #[Route(path: '/login/webauthn/options', name: 'nowo_auth_kit_webauthn_login_options', methods: ['GET'])]
    public function options(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        try {
            $json = $this->passkeys->loginOptions($profile, $request);
        } catch (WebauthnFailure) {
            return self::json(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return self::json($json);
    }

    #[Route(path: '/login/webauthn/assert', name: 'nowo_auth_kit_webauthn_login_assert', methods: ['POST'])]
    public function assert(Request $request): Response
    {
        $profile = $this->profile($request);
        $login   = $this->urls->generate($profile->routes['login']['name']);
        if ($this->tokens->getToken()?->getUser() instanceof UserInterface) {
            return new RedirectResponse($login, Response::HTTP_SEE_OTHER);
        }
        $form = self::form($this->forms, $profile, $this->urls);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || WebauthnCredentialFormType::credential($form) === '') {
            $this->flash->flash($request, 'error', 'webauthn.flash.failed');

            return new RedirectResponse($login, Response::HTTP_SEE_OTHER);
        }
        // Asked before the challenge is consumed: a sign-in that cannot happen is a host misconfiguration.
        if (!$this->loginAuthenticator->canSignIn($profile->firewall)) {
            $this->flash->flash($request, 'error', 'webauthn.flash.failed');

            return new RedirectResponse($login, Response::HTTP_SEE_OTHER);
        }
        try {
            $user = $this->passkeys->login($profile, $request, WebauthnCredentialFormType::credential($form));
        } catch (WebauthnFailure) {
            $this->flash->flash($request, 'error', 'webauthn.flash.failed');

            return new RedirectResponse($login, Response::HTTP_SEE_OTHER);
        }
        MfaFirstFactor::mark($request, 'webauthn');
        $response = $this->security->login($user, $this->loginAuthenticator->nameFor($profile->firewall), $profile->firewall);

        return $response ?? new RedirectResponse($profile->loginSuccessRoute !== null ? $this->urls->generate($profile->loginSuccessRoute) : '/', Response::HTTP_SEE_OTHER);
    }

    /**
     * The passkey form of the login page (hidden credential, CSRF token `nowo_auth_kit_webauthn_login.<profile>`).
     *
     * @return FormInterface<mixed>
     */
    public static function form(FormFactoryInterface $forms, ProfileSettings $profile, AuthKitUrlGenerator $urls): FormInterface
    {
        return $forms->createNamed('webauthn_login', WebauthnCredentialFormType::class, null, [
            'action'        => $urls->generate((string) $profile->webauthn['routes']['login_assert']['name']),
            'csrf_token_id' => 'nowo_auth_kit_webauthn_login.' . $profile->name,
        ]);
    }

    /**
     * A JSON answer that no cache keeps (options carry a fresh challenge).
     *
     * @param array<string, string>|string $data a JSON document, or data to encode
     */
    public static function json(array|string $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = is_string($data) ? JsonResponse::fromJsonString($data, $status) : new JsonResponse($data, $status);

        MfaChallengeController::noStore($response);

        return $response;
    }

    private function profile(Request $request): ProfileSettings
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$profile->webauthnPasswordless()) {
            throw new NotFoundHttpException('Passkey sign-in is not enabled for this profile.');
        }

        return $profile;
    }
}
