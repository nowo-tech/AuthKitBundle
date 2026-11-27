<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\Oidc\EndSessionEndpointRule;
use Nowo\AuthKitBundle\SocialLogin\Oidc\PostLogoutRedirectUri;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutHint;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Throwable;

use function array_values;
use function hash_equals;
use function http_build_query;
use function is_array;
use function is_string;
use function str_contains;

/**
 * OpenID Connect RP-Initiated Logout 1.0 (2.8.0). On sign-out, when the session holds the hint of an OIDC sign-in
 * ({@see RpLogoutSession}) whose credential opts in (`rp_initiated_logout`), the sign-out's redirect is replaced by one
 * to the provider's `end_session_endpoint` with `id_token_hint`, `client_id` and `post_logout_redirect_uri` (from the
 * host's allow-list only, {@see PostLogoutRedirectUri}).
 *
 * Deny by default, fail closed: the hint is removed from the session first, whatever happens next; any doubt (no
 * signed-in user, another user than the one the hint was issued for, a credential that is gone, disabled, opted out or
 * has another client id, an endpoint that no longer passes the HTTPS / SSRF rules, a sign-out answered with something
 * other than a redirect, any error) keeps the plain local sign-out. No outbound request is made here.
 *
 * The local sign-out always completes: this runs on the firewall's `LogoutEvent` after the default target redirect
 * (priority 64) and the multi-account sign-out (32), before the session is invalidated (`SessionLogoutListener`, 0); it never throws, and the
 * token and session are cleared by Symfony afterwards as for any sign-out. The provider being down only affects the
 * page the browser lands on. Stateless (worker-safe).
 */
final readonly class RpInitiatedLogoutSubscriber implements EventSubscriberInterface
{
    /**
     * After the default target (64) and the multi-account sign-out (`MultiAccountSubscriber::onLogout`, 32: with
     * `logout_scope: current` its chooser redirect is the target this subscriber may replace, audit ZDC-I1); before the
     * session is invalidated (`SessionLogoutListener`, 0).
     */
    public const PRIORITY = 16;

    public function __construct(
        private RpLogoutSession $hints,
        private SocialLoginCredentialRepository $credentials,
        private RequestProfileResolver $profiles,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', self::PRIORITY]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        // No session cookie, no hint: a stateless sign-out never starts a session here.
        if (!$request->hasPreviousSession()) {
            return;
        }

        try {
            $hint = $this->hints->take($request->getSession());
            $url  = $hint instanceof RpLogoutHint ? $this->endSessionUrl($event, $hint) : null;
        } catch (Throwable $exception) {
            // Class only: the message may hold provider data.
            $this->logger?->warning('AuthKit RP-initiated logout skipped; the local sign-out goes on.', ['exception' => $exception::class]);

            return;
        }

        if ($url !== null) {
            $event->setResponse(new RedirectResponse($url));
        }
    }

    private function endSessionUrl(LogoutEvent $event, RpLogoutHint $hint): ?string
    {
        $token    = $event->getToken();
        $response = $event->getResponse();
        if (!$token instanceof TokenInterface || !hash_equals($hint->userIdentifier, $token->getUserIdentifier())
            || ($response instanceof Response && !$response instanceof RedirectResponse)) {
            return null;
        }

        $credential = $this->credentials->findOneByProvider($hint->provider);
        if (!$credential instanceof SocialLoginCredential || !$credential->isEnabled() || !$credential->isRpInitiatedLogout()
            || !hash_equals($credential->getClientId(), $hint->clientId)) {
            return null;
        }
        // The endpoint passed this rule at discovery; checked again before the browser is sent there (audit ZDC-L1).
        if (!EndSessionEndpointRule::isSafe($hint->endSessionEndpoint)) {
            return null;
        }

        $params = [];
        if ($hint->idToken !== null) {
            $params['id_token_hint'] = $hint->idToken;
        }
        $params['client_id'] = $hint->clientId;
        $redirect            = PostLogoutRedirectUri::choose($this->allowList($event), $response?->getTargetUrl(), $event->getRequest());
        if ($redirect !== null) {
            $params['post_logout_redirect_uri'] = $redirect;
        }

        $endpoint = $hint->endSessionEndpoint;

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * @return list<string>
     */
    private function allowList(LogoutEvent $event): array
    {
        $uris    = $this->profiles->resolve($event->getRequest())->socialLogin['rp_logout']['post_logout_redirect_uris'] ?? [];
        $allowed = [];
        foreach (is_array($uris) ? array_values($uris) : [] as $uri) {
            if (is_string($uri)) {
                $allowed[] = $uri;
            }
        }

        return $allowed;
    }
}
