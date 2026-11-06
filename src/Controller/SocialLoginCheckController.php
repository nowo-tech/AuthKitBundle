<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialHandlerSignInRollback;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener as RememberMeResponseListener;
use Throwable;

/**
 * Completes the OAuth authorization-code callback and logs the user in. A host
 * {@see SocialCallbackHandlerInterface} can own the last step (1.37): every check below still runs first.
 */
final class SocialLoginCheckController
{
    /**
     * @param iterable<SocialCallbackHandlerInterface> $callbackHandlers
     */
    public function __construct(
        private readonly SocialLoginGate $socialLoginGate,
        private readonly SocialLoginCredentialRepository $credentials,
        private readonly OAuth2Client $oauth2Client,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialAccountLinker $accountLinker,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RequestProfileResolver $profileResolver,
        private readonly Security $security,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly iterable $callbackHandlers = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SocialHandlerSignInRollback $signInRollback = null,
    ) {
    }

    #[Route(path: '/login/social/{provider}/check', name: 'nowo_auth_kit_social_login_check', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['GET'])]
    public function check(Request $request, string $provider): Response
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        $handler  = $this->handlerFor($request, $provider);
        $state    = (string) $request->query->get('state', '');
        $code     = (string) $request->query->get('code', '');

        $credential = $this->socialLoginGate->isEnabled($profile->name) ? $this->credentials->findOneByProvider($provider) : null;
        if (!$credential instanceof SocialLoginCredential || !$credential->isEnabled()) {
            return $handler instanceof SocialCallbackHandlerInterface && $this->burnsState($provider, $state)
                ? $this->failed($request, $provider, $handler, SocialCallbackFailure::Unavailable, $loginUrl)
                : new RedirectResponse($loginUrl);
        }

        // A denied/failed authorization comes back with `error` and no `code` (RFC 6749 §4.1.2.1):
        // handle it before the code/state check so users see the provider_denied message.
        if ($request->query->has('error')) {
            // Burn a matching pending state so it cannot be replayed with a code later. Only that proves the request
            // belongs to this session's authorization: anything else is a link anyone can craft (GY-L2).
            return $this->burnsState($provider, $state) && $handler instanceof SocialCallbackHandlerInterface
                ? $this->failed($request, $provider, $handler, SocialCallbackFailure::ProviderDenied, $loginUrl, 'social.error.provider_denied')
                : $this->refused($request, $loginUrl, 'social.error.provider_denied');
        }

        $pending = $code !== '' && $state !== '' ? $this->stateStore->consumePending($provider, $state) : null;
        if ($pending === null) {
            return $this->refused($request, $loginUrl, 'social.error.invalid_state');
        }

        try {
            $redirectUri = $this->urlGenerator->generate(
                $profile->routes['social_login_check']['name'],
                ['provider' => $provider],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $tokens = $this->oauth2Client->exchangeCode($credential, $code, $redirectUri, $pending['code_verifier']);
            $social = $this->oauth2Client->fetchUserProfile($credential, $tokens['access_token']);
            if (!$handler instanceof SocialCallbackHandlerInterface) {
                $user = $this->accountLinker->linkOrCreate($profile, $provider, $social, $tokens);
                MfaFirstFactor::mark($request, 'social');
                $this->security->login($user, null, $profile->firewall);
            }
        } catch (RuntimeException $exception) {
            $this->logger?->warning('AuthKit social callback failed for provider {provider}.', ['provider' => $provider, 'exception' => $exception::class]);

            return $handler instanceof SocialCallbackHandlerInterface
                ? $this->failed($request, $provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed')
                : $this->refused($request, $loginUrl, 'social.error.failed');
        }

        if ($handler instanceof SocialCallbackHandlerInterface) {
            return $this->handled($request, $handler, new SocialCallbackContext($profile, $provider, $social, $tokens), $loginUrl);
        }

        $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

        return new RedirectResponse($this->urlGenerator->generate($target));
    }

    private function handlerFor(Request $request, string $provider): ?SocialCallbackHandlerInterface
    {
        foreach ($this->callbackHandlers as $handler) {
            if ($handler->supports($request, $provider)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Runs the handler. Whatever it throws, a sign-in it already made does not survive (GY-L1, GY-L6): the visitor must
     * not see a failure while the session is signed in as that account. HTTP exceptions keep their status (the handler's own 403, 404, 429);
     * anything else takes the failure path, logged by class because the message may hold provider data (GY-I1).
     */
    private function handled(Request $request, SocialCallbackHandlerInterface $handler, SocialCallbackContext $context, string $loginUrl): Response
    {
        $tokenBefore = $this->security->getToken();
        $snapshot    = $this->signInRollback?->snapshot($request) ?? [];
        $restorable  = $this->signInRollback?->canRestore($request, $tokenBefore) ?? false;
        MfaFirstFactor::mark($request, 'social');

        try {
            return $handler->handle($request, $context);
        } catch (Throwable $exception) {
            // Null: the handler signed out by itself, there is nothing to undo (GY-I10).
            $token = $this->security->getToken();
            if ($token instanceof TokenInterface && $token !== $tokenBefore) {
                $this->undoSignIn($request, $restorable ? $tokenBefore : null, $token, $snapshot);
            }
            $this->logger?->error('AuthKit social callback handler {handler} failed for provider {provider}.', ['handler' => $handler::class, 'provider' => $context->provider, 'exception' => $exception::class]);
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->failed($request, $context->provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed');
        }
    }

    /**
     * The handler's sign-in does not survive. Its remember-me cookie never leaves (it waits on the request), an earlier
     * session goes on (GY-L6), and a visitor who was anonymous stays anonymous, with the other accounts of the browser
     * still in the switcher.
     *
     * @param array<string, mixed> $snapshot
     */
    private function undoSignIn(Request $request, ?TokenInterface $tokenBefore, TokenInterface $failed, array $snapshot): void
    {
        $request->attributes->remove(RememberMeResponseListener::COOKIE_ATTR_NAME);
        if ($tokenBefore instanceof TokenInterface && $this->signInRollback instanceof SocialHandlerSignInRollback) {
            $this->signInRollback->restore($request, $tokenBefore, $failed, $snapshot);

            return;
        }
        $request->attributes->set(MultiAccountSubscriber::SCOPE_ATTRIBUTE, 'current');

        try {
            $this->security->logout(false);
        } finally {
            $this->signInRollback?->forceAnonymous($request);
        }
    }

    private function burnsState(string $provider, string $state): bool
    {
        return $state !== '' && $this->stateStore->consume($provider, $state);
    }

    /**
     * A failed callback that proved its state: the handler's answer, or (null) the flash and the redirect.
     */
    private function failed(Request $request, string $provider, SocialCallbackHandlerInterface $handler, SocialCallbackFailure $reason, string $loginUrl, ?string $flash = null): Response
    {
        $response = $handler->handleFailure($request, $provider, $reason);
        if ($response instanceof Response) {
            return $response;
        }

        return $flash !== null ? $this->refused($request, $loginUrl, $flash) : new RedirectResponse($loginUrl);
    }

    private function refused(Request $request, string $loginUrl, string $flash): RedirectResponse
    {
        $this->flashMessenger->flash($request, 'error', $flash);

        return new RedirectResponse($loginUrl);
    }
}
