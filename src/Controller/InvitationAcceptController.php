<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Form\FormCsrf;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationOpeningEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\ProviderMarkResolver;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialHandlerSignInRollback;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\AuthKitBundle\View\ProviderMark;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;
use Twig\Environment;

use function hash_equals;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function mb_substr;
use function preg_replace;
use function time;
use function trim;

/**
 * The public invitation page, on AuthKit's registration form and flow. Opening the link (`/invitation/{token}`) moves
 * the token hash into the session and redirects (303) to the token-less URL, so the token never stays in the address
 * bar, the history, a Referer or a rendered page. The page then shows the registration form with the invited e-mail
 * (read-only with `invitations.lock_email`, enforced server-side) and the profile's registration fields; submitting
 * creates the account ({@see InvitationAcceptor::accept()}) and signs it in.
 *
 * An unknown, used, revoked or replaced link answers the same neutral page; an expired one (still the current token)
 * says so; neither shows any invitee data. Opening a link and submitting are rate limited per client IP
 * (`accept_rate_limit`); signed-in visitors are asked to sign out first (no account merge). Responses are
 * `private, no-store`, `noindex` and `Referrer-Policy: no-referrer`; redirects are path-only. 1.33: an expired or
 * revoked link dispatches {@see InvitationLinkRejectedEvent}; a host refusal
 * ({@see InvitationAcceptingEvent}, grant) shows its translatable reason.
 *
 * 1.42: the page receives the invitation as `invitation` ({@see InvitationSnapshot}: no token, no hash); before the
 * form is shown (GET) {@see InvitationAcceptor::precheck()} lets the host refuse with a reason
 * ({@see InvitationOpeningEvent}, the `unavailable` page) without consuming anything. With
 * `invitations.social.accept_page` the page lists the enabled social providers, each a CSRF form posting to
 * {@see self::social()}, and this controller completes the acceptance on the OAuth callback
 * ({@see SocialCallbackHandlerInterface}: only after AuthKit verified the state, PKCE and the code; the provider must
 * assert a verified e-mail equal to the invited one).
 */
final class InvitationAcceptController implements SocialCallbackHandlerInterface
{
    /** Session key prefix of the token hash (one per profile); hosts use {@see InvitationAcceptor::pending()}. */
    public const SESSION_KEY = InvitationAcceptor::SESSION_KEY;

    public const CSRF_TOKEN_PREFIX = 'nowo_auth_kit_invitation_accept';

    /** CSRF token id prefix of the social provider forms of the accept page (1.42). */
    public const SOCIAL_CSRF_TOKEN_PREFIX = 'nowo_auth_kit_invitation_social';

    /** Session key of the social acceptance in progress (1.42): the profile, the provider and when it started. */
    public const SOCIAL_SESSION_KEY = '_nowo_auth_kit_invitation_social';

    /** An abandoned social acceptance stops claiming the provider's callbacks after this many seconds (1.42). */
    public const SOCIAL_PENDING_SECONDS = 900;

    /** Request attribute set once a social acceptance committed (its failure path then says so, KL-I8). */
    public const SOCIAL_ACCEPTED_ATTRIBUTE = '_nowo_auth_kit_invitation_social_accepted';

    /** Longest provider display name put into `invitations.social.name_field` (KL-I10). */
    public const SOCIAL_NAME_MAX_LENGTH = 180;

    public function __construct(
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly RequestProfileResolver $profileResolver,
        private readonly RegistrationGate $registrationGate,
        private readonly InvitationAcceptor $acceptor,
        private readonly AuthKitAttemptLimiter $attemptLimiter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly Security $security,
        private readonly SlideToConfirmTypeResolver $slideToConfirmTypeResolver = new SlideToConfirmTypeResolver(),
        private readonly AuthPageFactory $pages = new AuthPageFactory(),
        private readonly ProgrammaticLoginAuthenticator $loginAuthenticator = new ProgrammaticLoginAuthenticator(),
        private readonly ?SocialHandlerSignInRollback $signInRollback = null,
        private readonly ?SocialLoginGate $socialLoginGate = null,
        private readonly ?SocialLoginCredentialRepository $socialCredentials = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
        private readonly ?SocialAccountLinker $accountLinker = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?OAuth2Client $oauth2Client = null,
        private readonly ?SocialLoginStateStore $stateStore = null,
        private readonly ProviderMarkResolver $marks = new ProviderMarkResolver(),
    ) {
    }

    #[Route(path: '/invitation/{token}', name: 'nowo_auth_kit_invitation_accept', requirements: ['token' => InvitationTokens::PATTERN], methods: ['GET', 'POST'])]
    public function accept(Request $request, ?string $token = null): Response
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$this->registrationGate->isInvitationAcceptanceAllowed($profile->name)) {
            throw new NotFoundHttpException('Invitations are disabled.');
        }
        $settings = $profile->invitations;

        if (($token !== null || $request->isMethod('POST'))
            && !$this->attemptLimiter->consume(
                'invitation_accept:' . $profile->name . ':' . ($request->getClientIp() ?? 'unknown'),
                (int) ($settings['accept_rate_limit'] ?? 10),
                (int) ($settings['accept_rate_window'] ?? 900),
            )
        ) {
            return $this->notice($profile, 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            return $this->notice($profile, 'signed_in');
        }

        $session = $request->getSession();
        $key     = self::SESSION_KEY . $profile->name;
        if ($token !== null) {
            $session->set($key, $this->acceptor->hashToken($token));

            return $this->redirect($this->urlGenerator->generate(self::routeName($profile)), Response::HTTP_SEE_OTHER);
        }

        $tokenHash = $session->get($key);
        // 1.33: an expired or revoked link dispatches InvitationLinkRejectedEvent (once: the session entry goes below).
        [$status, $invitation] = is_string($tokenHash) ? $this->acceptor->lookup($profile->name, $tokenHash, true) : [null, null];
        if (!$invitation instanceof Invitation || !is_string($tokenHash)) {
            $session->remove($key);

            return $this->notice($profile, 'invalid');
        }
        if ($status === InvitationStatus::Expired) {
            $session->remove($key);

            return $this->notice($profile, 'expired');
        }

        if (!$request->isMethod('POST')) {
            // 1.42: the host may refuse before the form is shown (e.g. no seat left); nothing is consumed, the link
            // stays usable, so the session entry stays too.
            $refused = $this->acceptor->precheck($invitation);
            if ($refused instanceof InvitationAcceptance) {
                return $this->notice($profile, 'unavailable', Response::HTTP_OK, $refused->refusal);
            }
        }

        $emailField = $profile->invitationEmailField();
        $locked     = ($settings['lock_email'] ?? true) === true;
        $form       = $this->formFactory->create(RegistrationFormType::class, $this->prefill($profile, $invitation, $emailField), [
            'profile'               => $profile->name,
            'invitation_lock_email' => $locked,
            // Bound to the action and the invitation (a token of another invitation or of the open registration fails).
            'invitation_csrf_token_id' => self::csrfTokenId($profile->name, $invitation->getPublicId()),
        ]);

        if ($request->isMethod('POST')) {
            // SEC-004 P2: never create an account through a form without CSRF protection (fails closed).
            if (!FormCsrf::isProtected($form)) {
                throw new AccessDeniedHttpException('CSRF protection is not available.');
            }
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = (array) $form->getData();
                if ($locked && $emailField !== null) {
                    $data[$emailField] = $invitation->getEmail();
                }
                // The sign-in below could not happen: the invitation stays open and no account is created for it.
                if (!$this->loginAuthenticator->canSignIn($profile->firewall)) {
                    return $this->notice($profile, 'not_accepted');
                }
                $acceptance = $this->acceptor->attempt($invitation, $tokenHash, $data);
                $session->remove($key);
                if (!$acceptance->user instanceof UserInterface) {
                    // 1.33: the host's translatable reason (pre-accept event or grant), else the neutral text.
                    return $this->notice($profile, 'not_accepted', Response::HTTP_OK, $acceptance->refusal);
                }

                return $this->signIn($request, $profile, $acceptance->user);
            }
        }

        $formView = $form->createView();
        $message  = $invitation->getMessage();
        $page     = $this->pages->create('invitation_accept', $profile, $formView, [
            $this->pages->link('register.login_link', $this->urlGenerator->generate($profile->routes['login']['name'])),
        ]);
        $content = $this->twig->render($profile->template('invitation_accept'), $this->pages->context('invitation_accept', $page, $profile, [
            'registration_form'  => $formView,
            'login_route'        => $profile->routes['login']['name'],
            'invitation_message' => $message,
            // 1.42: read-only copy (no token, no hash) and the social providers of the page (social.accept_page).
            'invitation'                  => $this->acceptor->snapshot($invitation),
            'invitation_social_providers' => $this->socialProviders($profile, $invitation),
            'slide_to_confirm_mode'       => $this->slideToConfirmTypeResolver->resolveRegistrationSlideMode(
                $profile->registrationFields,
                $profile->slideToConfirm,
            ),
        ]));

        return $this->private(new Response($content, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** CSRF token id of the accept form of one invitation (`/` separates the parts). */
    public static function csrfTokenId(string $profile, string $publicId): string
    {
        return self::CSRF_TOKEN_PREFIX . '/' . $profile . '/' . $publicId;
    }

    /** CSRF token id of the social provider forms of one invitation (1.42). */
    public static function socialCsrfTokenId(string $profile, string $publicId): string
    {
        return self::SOCIAL_CSRF_TOKEN_PREFIX . '/' . $profile . '/' . $publicId;
    }

    /**
     * Starts a social acceptance of the invitation open in this browser (1.42, `invitations.social.accept_page`): the
     * CSRF form of the accept page, bound to the invitation. Remembers the profile and provider in the session and hands
     * over to AuthKit's OAuth start; the callback comes back to {@see self::handle()}. The link token never reaches this
     * route (the session holds its hash). Rate limited like the accept page.
     */
    #[Route(path: '/invitation/social/{provider}', name: 'nowo_auth_kit_invitation_social', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['POST'])]
    public function social(Request $request, string $provider): Response
    {
        $profile = $this->profileResolver->resolve($request);
        if (!$this->registrationGate->isInvitationAcceptanceAllowed($profile->name) || !$this->socialAcceptEnabled($profile)) {
            throw new NotFoundHttpException('Social acceptance of invitations is disabled.');
        }
        $settings = $profile->invitations;
        if (!$this->attemptLimiter->consume(
            'invitation_accept:' . $profile->name . ':' . ($request->getClientIp() ?? 'unknown'),
            (int) ($settings['accept_rate_limit'] ?? 10),
            (int) ($settings['accept_rate_window'] ?? 900),
        )) {
            return $this->notice($profile, 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            return $this->notice($profile, 'signed_in');
        }

        $session               = $request->getSession();
        $tokenHash             = $session->get(self::SESSION_KEY . $profile->name);
        [$status, $invitation] = is_string($tokenHash) ? $this->acceptor->lookup($profile->name, $tokenHash) : [null, null];
        if (!$invitation instanceof Invitation || $status !== InvitationStatus::Pending) {
            // Expired, unknown or none opened: the accept page answers (expired / neutral notice).
            return $this->redirect($this->urlGenerator->generate(self::routeName($profile)), Response::HTTP_SEE_OTHER);
        }
        $form = $this->csrfForms()->createNamed($request->getPathInfo(), self::socialCsrfTokenId($profile->name, $invitation->getPublicId()));
        // SEC-004 P2, fail closed. FormKit's CSRF-only type cannot even be built without the CSRF extension.
        if (!FormCsrf::isProtected($form)) {
            throw new AccessDeniedHttpException('CSRF protection is not available.'); // @codeCoverageIgnore
        }
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $credential = $this->providerCredential($provider);
        if (!$credential instanceof SocialLoginCredential || !$this->oauth2Client instanceof OAuth2Client || !$this->stateStore instanceof SocialLoginStateStore) {
            return $this->redirect($this->urlGenerator->generate(self::routeName($profile)), Response::HTTP_SEE_OTHER);
        }
        $refused = $this->acceptor->precheck($invitation);
        if ($refused instanceof InvitationAcceptance) {
            return $this->notice($profile, 'unavailable', Response::HTTP_OK, $refused->refusal);
        }

        // The OAuth start happens here (as in SocialLoginStartController), so the marker holds the exact state of this
        // authorization (KL-L2: no other callback of the provider is claimed) and the invitation it is for (KL-L1: a
        // link opened meanwhile is not accepted in its place).
        $redirectUri = $this->urlGenerator->generate($profile->routes['social_login_check']['name'], ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL);
        /** @var array<string, bool> $pkceOverrides */
        $pkceOverrides = $profile->socialLogin['pkce'] ?? [];
        /** @var array<string, mixed> $authorizationParams */
        $authorizationParams = $profile->socialLogin['authorization_params'] ?? [];
        $codeVerifier        = $this->oauth2Client->usesPkce($credential, $pkceOverrides) ? Pkce::generateVerifier() : null;
        $state               = $this->stateStore->issue($provider, $codeVerifier);
        $session->set(self::SOCIAL_SESSION_KEY, [
            'profile'  => $profile->name,
            'provider' => $provider,
            'at'       => $this->now(),
            'state'    => $state,
            'hash'     => $tokenHash,
        ]);

        return $this->private(new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->oauth2Client->buildAuthorizeUrl($credential, $redirectUri, $state, $codeVerifier, $authorizationParams)]));
    }

    /**
     * The OAuth callback belongs to the social acceptance started from the accept page of this profile, with this
     * provider, less than {@see self::SOCIAL_PENDING_SECONDS} ago, and carries the state of that very start (KL-L2):
     * an ordinary sign-in with the provider later in the same browser has another state and keeps the default flow.
     * AuthKit still verifies that state (single use, session-bound) before {@see self::handle()} runs.
     */
    public function supports(Request $request, string $provider): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $pending = $request->getSession()->get(self::SOCIAL_SESSION_KEY);
        if (!is_array($pending) || ($pending['provider'] ?? null) !== $provider || !is_int($pending['at'] ?? null) || $this->now() > $pending['at'] + self::SOCIAL_PENDING_SECONDS
            || !is_string($pending['state'] ?? null) || !hash_equals($pending['state'], (string) $request->query->get('state', ''))) {
            return false;
        }
        $profile = $this->profileResolver->resolve($request);

        return ($pending['profile'] ?? null) === $profile->name && $this->socialAcceptEnabled($profile);
    }

    /**
     * The verified callback (state, PKCE and code already checked by AuthKit): accepts the invitation pending in the
     * session ({@see InvitationAcceptor::acceptPendingForSocial()}: verified provider e-mail equal to the invited one),
     * links the provider identity to the new account when the linker allows it, and signs it in last.
     */
    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        $session = $request->getSession();
        $pending = $session->get(self::SOCIAL_SESSION_KEY);
        $session->remove(self::SOCIAL_SESSION_KEY);
        $profile = $context->profile;
        $started = is_array($pending) && is_string($pending['hash'] ?? null) ? $pending['hash'] : '';
        $current = $session->get(self::SESSION_KEY . $profile->name);
        // KL-L1: the invitation open in this browser must still be the one the acceptance started for; another link
        // opened meanwhile (e.g. by a third-party page) is not accepted in its place. Nothing is consumed.
        if ($started === '' || !is_string($current) || !hash_equals($started, $current)) {
            return $this->notice($profile, 'invalid');
        }
        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            return $this->notice($profile, 'signed_in');
        }
        // GY-L9: the sign-in below could not happen, so the invitation is not consumed.
        if (!$this->loginAuthenticator->canSignIn($profile->firewall)) {
            return $this->notice($profile, 'not_accepted');
        }
        $social = $context->socialProfile;
        $email  = $context->verifiedEmail();
        $result = $this->acceptor->acceptPendingForSocial(
            $session,
            $profile->name,
            new SocialUserProfile($social->id, $email, $social->name, $social->raw, $email !== null),
            $this->socialFormData($profile, $social->name),
        );
        $user = $result->user;
        if (!$user instanceof UserInterface) {
            return match ($result->status) {
                InvitationAcceptance::EMAIL_MISMATCH => $this->notice($profile, 'social_mismatch', Response::HTTP_OK, null, $session->has(self::SESSION_KEY . $profile->name)),
                InvitationAcceptance::EXPIRED        => $this->notice($profile, 'expired'),
                InvitationAcceptance::NO_INVITATION  => $this->notice($profile, 'invalid'),
                default                              => $this->notice($profile, 'not_accepted', Response::HTTP_OK, $result->refusal),
            };
        }
        // KL-I8: from here on the invitation is used; a failing sign-in answers "accepted, sign in".
        $request->attributes->set(self::SOCIAL_ACCEPTED_ATTRIBUTE, true);
        if ($this->accountLinker instanceof SocialAccountLinker && $result->invitation instanceof InvitationSnapshot) {
            try {
                // The invited e-mail as stored (the provider may spell it with capitals); verified, it matched above.
                // `link_existing_account: deny` is refused with accept_page at compile time (KL-L3).
                $this->accountLinker->linkOrCreate($profile, $context->provider, new SocialUserProfile($social->id, $result->invitation->email, $social->name, $social->raw, true), $context->tokens);
            } catch (Throwable $exception) {
                // A host lookup or a storage failure: the accepted account signs in now and may link the provider later.
                $this->logger?->notice('AuthKit could not link the social identity of an accepted invitation.', ['provider' => $context->provider, 'reason' => $exception::class]);
            }
        }

        $login  = $this->urlGenerator->generate($profile->routes['login']['name']);
        $target = $profile->loginSuccessRoute !== null ? $this->urlGenerator->generate($profile->loginSuccessRoute) : $login;

        return $this->security->login($user, $this->loginAuthenticator->nameForAccountFlow($profile->firewall), $profile->firewall) ?? $this->redirect($target);
    }

    /**
     * The provider callback failed after proving its state: the invitation is untouched and still open in the session.
     */
    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): Response
    {
        $request->getSession()->remove(self::SOCIAL_SESSION_KEY);
        $profile = $this->profileResolver->resolve($request);
        // KL-I8: the sign-in failed after the acceptance committed: the account exists and the invitation is used.
        if ($request->attributes->get(self::SOCIAL_ACCEPTED_ATTRIBUTE) === true) {
            return $this->notice($profile, 'accepted');
        }

        return $this->notice($profile, 'social_failed', Response::HTTP_OK, null, true);
    }

    public static function routeName(ProfileSettings $profile): string
    {
        /** @var array{accept: array{name: string}} $routes */
        $routes = $profile->invitations['routes'];

        return $routes['accept']['name'];
    }

    /**
     * Initial data of the registration form: the invited e-mail, plus scalar payload values of mapped, non-secret
     * registration fields (never a password; unmapped fields ignore the form data).
     *
     * @return array<string, mixed>
     */
    private function prefill(ProfileSettings $profile, Invitation $invitation, ?string $emailField): array
    {
        $payload = $invitation->getPayload();
        $data    = [];
        foreach ($profile->registrationFields as $field) {
            $name = (string) $field['name'];
            if (($field['type'] ?? 'text') === 'password' || ($field['hash'] ?? false) === true || ($field['mapped'] ?? true) === false || !isset($payload[$name])) {
                continue;
            }
            $value = $payload[$name];
            if (is_scalar($value)) {
                $data[$name] = ($field['type'] ?? 'text') === 'checkbox' ? (is_bool($value) ? $value : (bool) $value) : (string) $value;
            }
        }
        if ($emailField !== null) {
            $data[$emailField] = $invitation->getEmail();
        }

        return $data;
    }

    private function signIn(Request $request, ProfileSettings $profile, UserInterface $user): Response
    {
        $login  = $this->urlGenerator->generate($profile->routes['login']['name']);
        $target = $profile->loginSuccessRoute !== null ? $this->urlGenerator->generate($profile->loginSuccessRoute) : $login;

        // SEC-004 L6: a regular authentication (user checker, LoginSuccessEvent, session fixation strategy).
        try {
            MfaFirstFactor::mark($request, 'invitation');
            $signIn   = fn (): ?Response => $this->security->login($user, $this->loginAuthenticator->nameForAccountFlow($profile->firewall), $profile->firewall);
            $response = $this->signInRollback instanceof SocialHandlerSignInRollback ? $this->signInRollback->guard($request, $signIn) : $signIn();
        } catch (AuthenticationException) {
            return $this->redirect($login);
        }

        return $response ?? $this->redirect($target);
    }

    /**
     * A form-less page: `invalid` (unknown, used, revoked or replaced link), `expired`, `rate_limited`,
     * `signed_in`, `not_accepted`; 1.42: `unavailable` (refused when opened), `social_mismatch`, `social_failed`,
     * `accepted` (a social acceptance committed but the sign-in failed). No invitee data on any of them.
     */
    private function notice(ProfileSettings $profile, string $kind, int $status = Response::HTTP_OK, ?InvitationRefusal $refusal = null, bool $backToInvitation = false): Response
    {
        $links = [$this->pages->link('invitation.page.sign_in', $this->urlGenerator->generate($profile->routes['login']['name']))];
        if ($kind === 'signed_in') {
            $links = [$this->pages->link('invitation.page.sign_out', $this->urlGenerator->generate($profile->routes['logout']['name']))];
        }
        if ($backToInvitation) {
            // 1.42: the invitation is still open in this browser (social failure, kept after an e-mail mismatch).
            $links = [$this->pages->link('invitation.page.back_to_invitation', $this->urlGenerator->generate(self::routeName($profile))), ...$links];
        }
        $page = $this->pages->notice(
            'invitation_invalid',
            $profile,
            AuthPageFactory::label('invitation.page.' . $kind . '_title'),
            $refusal instanceof InvitationRefusal
                ? new Label($refusal->key, $refusal->domain, $refusal->parameters)
                : AuthPageFactory::label('invitation.page.' . $kind . '_body'),
            $links,
            ['data-nowo-auth-kit-invitation' => $kind],
        );
        $content = $this->twig->render($profile->template('invitation_invalid'), $this->pages->context('invitation_invalid', $page, $profile, [
            'invitation_kind' => $kind,
        ]));

        return $this->private(new Response($content, $status));
    }

    private function socialAcceptEnabled(ProfileSettings $profile): bool
    {
        return ($profile->invitations['social']['accept_page'] ?? false) === true && $this->socialLoginGate?->isEnabled($profile->name) === true;
    }

    /**
     * The enabled social providers of the page (not the enterprise SSO ones), each with its CSRF form (1.42).
     *
     * @return list<array{provider: string, label: Label, mark: ?ProviderMark, form: FormView}>
     */
    private function socialProviders(ProfileSettings $profile, Invitation $invitation): array
    {
        if (!$this->socialAcceptEnabled($profile)) {
            return [];
        }
        /** @var array{social: array{name: string}} $routes */
        $routes = $profile->invitations['routes'];
        /** @var array<string, mixed> $markOverrides */
        $markOverrides = $profile->socialLogin['provider_marks'] ?? [];
        $providers     = [];
        foreach ($this->socialCredentials?->findEnabledOrdered() ?? [] as $credential) {
            if ($credential->isEnterpriseSso()) {
                continue;
            }
            $provider    = $credential->getProvider();
            $providers[] = [
                'provider' => $provider,
                'label'    => new Label('invitation.page.social_continue', NowoAuthKitBundle::TRANSLATION_DOMAIN, ['%provider%' => $credential->getLabel()]),
                // 1.48: the same brand mark as the login buttons (social_login.provider_marks).
                'mark' => $this->marks->resolve($provider, $markOverrides),
                'form' => $this->csrfForms()->createNamed(
                    $this->urlGenerator->generate($routes['social']['name'], ['provider' => $provider]),
                    self::socialCsrfTokenId($profile->name, $invitation->getPublicId()),
                )->createView(),
            ];
        }

        return $providers;
    }

    private function providerCredential(string $provider): ?SocialLoginCredential
    {
        foreach ($this->socialCredentials?->findEnabledOrdered() ?? [] as $credential) {
            if (!$credential->isEnterpriseSso() && $credential->getProvider() === $provider) {
                return $credential;
            }
        }

        return null;
    }

    /**
     * Registration data of a social acceptance: the provider's display name in `invitations.social.name_field`, if set.
     *
     * @return array<string, mixed>
     */
    private function socialFormData(ProfileSettings $profile, ?string $name): array
    {
        $field = $profile->invitations['social']['name_field'] ?? null;
        // KL-I10: no control or format (bidi) characters and a bounded length: the provider value is not form input.
        $name = trim(mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', (string) $name)), 0, self::SOCIAL_NAME_MAX_LENGTH));

        return is_string($field) && $name !== '' ? [$field => $name] : [];
    }

    private function csrfForms(): CsrfOnlyFormFactory
    {
        return $this->csrfForms ?? new CsrfOnlyFormFactory($this->formFactory);
    }

    private function now(): int
    {
        return $this->clock instanceof ClockInterface ? $this->clock->now()->getTimestamp() : time();
    }

    private function redirect(string $path, int $status = Response::HTTP_FOUND): Response
    {
        return $this->private(new Response('', $status, ['Location' => InvitationAdminController::pathOnly($path)]));
    }

    private function private(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
