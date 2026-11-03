<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use LogicException;
use Nowo\AuthKitBundle\Mfa\MfaLoginCompleter;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * Keeps a half-authenticated browser inside the second step (1.34), right after the firewall (`kernel.request`
 * priority 7): while the token is a {@see MfaPendingToken}, only the challenge / enrolment routes, the logout route
 * and `mfa.allowed_routes` run; HTML requests are redirected to the challenge (GET targets are kept), JSON / XHR
 * requests get `401 {"error":"mfa_required"}`. An expired pending sign-in is ended. The pending token has no roles,
 * so `access_control` and voters refuse it anyway (defence in depth). Also writes the queued trusted-device cookie.
 */
final readonly class MfaContainmentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private MfaManager $manager,
        private MfaLoginCompleter $completer,
        private AuthKitUrlGenerator $urls,
        private string $unlocalizedSuffix = '_unlocalized',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 7],
            KernelEvents::EXCEPTION => ['onException', 2],
            KernelEvents::RESPONSE  => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $token = $this->tokens->getToken();
        if (!$event->isMainRequest() || !$token instanceof MfaPendingToken) {
            return;
        }
        $response = $this->contain($event->getRequest(), $token, true);
        if ($response instanceof Response) {
            $event->setResponse($response);
        }
    }

    /**
     * `access_control` runs inside the firewall, before `onRequest()`: a pending token (no roles) denied there is sent
     * to the challenge as well (before the firewall's exception listener answers 403).
     */
    public function onException(ExceptionEvent $event): void
    {
        $token = $this->tokens->getToken();
        if (!$event->isMainRequest() || !$token instanceof MfaPendingToken || !$event->getThrowable() instanceof AccessDeniedException) {
            return;
        }
        $event->setResponse($this->contain($event->getRequest(), $token, false) ?? throw new LogicException('A denied request is always contained.'));
    }

    /**
     * The answer to a pending request, or null when it may run (`$allowRoutes`: the challenge, enrolment, logout and
     * `mfa.allowed_routes`): an expired sign-in is ended; JSON / XHR get 401 `mfa_required`; anything else is
     * redirected to the challenge (or enrolment), keeping a GET target.
     */
    private function contain(Request $request, MfaPendingToken $token, bool $allowRoutes): ?Response
    {
        $profile = $this->manager->profileNamed($token->profile());
        if ($this->manager->now()->getTimestamp() - $token->createdAt() >= (int) ($profile->mfa['pending_lifetime'] ?? 300)) {
            return $this->completer->abort($request, $token, 'mfa.flash.expired');
        }
        if ($allowRoutes && $this->allowed($request, $profile)) {
            return null;
        }
        if ($this->wantsJson($request)) {
            $response = new JsonResponse(['error' => 'mfa_required'], Response::HTTP_UNAUTHORIZED);
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }
        if ($request->isMethod('GET') && $token->targetPath() === null) {
            $token->setTargetPath(MfaAuthenticationSubscriber::localTarget($request->getRequestUri(), $request));
        }

        return new RedirectResponse($this->urls->generate((string) $profile->mfa['routes'][$token->mode()]['name']));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            MfaTrustedDevices::writeCookie($event->getRequest(), $event->getResponse());
        }
    }

    private function allowed(Request $request, ProfileSettings $profile): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }
        if (str_starts_with($route, '_wdt') || str_starts_with($route, '_profiler')) {
            return true;
        }
        $names = [
            (string) $profile->mfa['routes']['challenge']['name'],
            (string) $profile->mfa['routes']['setup']['name'],
            $profile->routes['logout']['name'],
            ...(is_array($profile->mfa['allowed_routes'] ?? null) ? $profile->mfa['allowed_routes'] : []),
        ];
        foreach ($names as $name) {
            if ($route === $name || $route === $name . $this->unlocalizedSuffix) {
                return true;
            }
        }

        return false;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json' || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
