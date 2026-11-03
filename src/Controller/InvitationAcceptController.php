<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Form\FormCsrf;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptingEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationLinkRejectedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UiKitBundle\View\Label;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function is_bool;
use function is_scalar;
use function is_string;

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
 */
final class InvitationAcceptController
{
    /** Session key prefix of the token hash (one per profile); hosts use {@see InvitationAcceptor::pending()}. */
    public const SESSION_KEY = InvitationAcceptor::SESSION_KEY;

    public const CSRF_TOKEN_PREFIX = 'nowo_auth_kit_invitation_accept';

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
            'registration_form'     => $formView,
            'login_route'           => $profile->routes['login']['name'],
            'invitation_message'    => $message,
            'slide_to_confirm_mode' => $this->slideToConfirmTypeResolver->resolveRegistrationSlideMode(
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
            $response = $this->security->login($user, 'form_login', $profile->firewall);
        } catch (AuthenticationException) {
            return $this->redirect($login);
        }

        return $response ?? $this->redirect($target);
    }

    /**
     * A form-less page: `invalid` (unknown, used, revoked or replaced link), `expired`, `rate_limited`,
     * `signed_in`, `not_accepted`. No invitee data on any of them.
     */
    private function notice(ProfileSettings $profile, string $kind, int $status = Response::HTTP_OK, ?InvitationRefusal $refusal = null): Response
    {
        $links = [$this->pages->link('invitation.page.sign_in', $this->urlGenerator->generate($profile->routes['login']['name']))];
        if ($kind === 'signed_in') {
            $links = [$this->pages->link('invitation.page.sign_out', $this->urlGenerator->generate($profile->routes['logout']['name']))];
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
