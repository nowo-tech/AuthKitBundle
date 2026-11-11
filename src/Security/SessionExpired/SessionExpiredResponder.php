<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\SessionExpired;

use Nowo\AuthKitBundle\Security\LoginTarget\NavigationKind;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\Exception\LogoutException;
use Throwable;

use function in_array;
use function is_string;

/**
 * Expired-session answer for background calls (1.44, opt-in `nowo_auth_kit.session_expired.enabled`).
 *
 * When a fetch / XHR / EventSource / JSON request of a guarded, stateful firewall needs authentication (no token, or
 * a token that is not fully authenticated hitting an access check), it gets a `401` `application/problem+json` with a
 * stable `code` and a marker header instead of the firewall's `302` to the login page. The firewall's exception
 * listener (priority 1) never runs for it, so the request is not saved as the post-login target either. Page
 * navigations keep the firewall's entry point (the login redirect).
 *
 * Mirrors the firewall: an access denial of a fully authenticated user stays a 403, and an `AccountStatusException`
 * clears the token. Stateless (worker safe).
 */
final readonly class SessionExpiredResponder
{
    /**
     * @param list<string> $firewalls
     */
    public function __construct(
        private Security $security,
        private TokenStorageInterface $tokenStorage,
        private AuthenticationTrustResolverInterface $trustResolver,
        private array $firewalls,
        private string $headerName = 'X-Auth-Kit',
        private string $headerValue = 'session-expired',
        private string $problemType = 'about:blank',
        private string $problemTitle = 'Unauthorized',
        private string $code = 'session_expired',
    ) {
    }

    /**
     * `kernel.exception`, priority 2: before the firewall's exception listener (1).
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || NavigationKind::expectsHtml($request)) {
            return;
        }
        $config   = $this->security->getFirewallConfig($request);
        $firewall = $config?->getName();
        if (!is_string($firewall) || !$config->isSecurityEnabled() || $config->isStateless() || !in_array($firewall, $this->firewalls, true)) {
            return;
        }
        if (!$this->needsAuthentication($event->getThrowable())) {
            return;
        }

        $event->setResponse($this->response());
        $event->allowCustomResponseCode();
    }

    /**
     * The 401 answer (also usable by a host entry point).
     */
    public function response(): JsonResponse
    {
        $response = new JsonResponse([
            'type'   => $this->problemType,
            'title'  => $this->problemTitle,
            'status' => Response::HTTP_UNAUTHORIZED,
            'code'   => $this->code,
        ], Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/problem+json']);
        $response->headers->set($this->headerName, $this->headerValue);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The firewall would start authentication for this exception (same walk as its exception listener).
     */
    private function needsAuthentication(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof AuthenticationException) {
                if ($current instanceof AccountStatusException) {
                    $this->tokenStorage->setToken(null);
                }

                return true;
            }
            if ($current instanceof AccessDeniedException) {
                return !$this->trustResolver->isFullFledged($this->tokenStorage->getToken());
            }
            if ($current instanceof LazyResponseException || $current instanceof LogoutException) {
                return false;
            }
        }

        return false;
    }
}
