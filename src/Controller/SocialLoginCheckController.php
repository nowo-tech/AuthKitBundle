<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Enum\SocialLoginMode;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Port\SocialCallbackStepGate;
use Nowo\AuthKitBundle\Port\SocialLoginTestGate;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialPolicy;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\AuthKitBundle\SocialLogin\SocialHandlerSignInRollback;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function get_debug_type;
use function is_string;
use function sprintf;

/**
 * Completes the OAuth authorization-code callback and logs the user in. A host
 * {@see SocialCallbackHandlerInterface} can own the last step (1.37): every check below still runs first.
 */
final class SocialLoginCheckController
{
    /**
     * @param iterable<mixed> $callbackHandlers tagged `nowo_auth_kit.social_callback_handler` (either interface; checked at build time)
     * @param iterable<mixed> $testHandlers 2.7.0: tagged {@see SocialLoginTestHandlerInterface::TAG}
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
        private readonly ProgrammaticLoginAuthenticator $loginAuthenticator = new ProgrammaticLoginAuthenticator(),
        private readonly ?OidcClient $oidcClient = null,
        private readonly ?SocialFormPostRelay $formPostRelay = null,
        private readonly iterable $testHandlers = [],
    ) {
    }

    /**
     * GET for the providers that redirect back; POST (2.3.0) for `response_mode=form_post` (Sign in with Apple): the
     * provider's cross-site POST is relayed first ({@see SocialFormPostRelay}), the relayed same-site POST is checked.
     */
    #[Route(path: '/login/social/{provider}/check', name: 'nowo_auth_kit_social_login_check', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['GET', 'POST'])]
    public function check(Request $request, string $provider): Response
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        $posted   = $request->isMethod('POST');
        if ($posted && !SocialFormPostRelay::isRelayed($request)) {
            // No session, no flash, no handler here: the visitor's session cookie did not come with this request.
            return $this->relayFormPost($request, $provider, $profile, $loginUrl);
        }

        $params = $posted ? $request->request : $request->query;
        $state  = (string) $params->get('state', '');
        $code   = (string) $params->get('code', '');
        // 2.7.0: a test sign-in is known only from the marker stored with this session's state (SocialLoginTestStart),
        // never from a URL parameter. Its callback reaches the host's test handler alone and never signs in.
        $testing = $this->stateStore->isTestPending($provider, $state);
        $handler = $testing ? $this->testHandlerFor($profile) : $this->handlerFor($request, $provider, $profile);
        if ($testing && !$handler instanceof SocialCallbackHandlerInterface) {
            // The test handler went away since the start: burn the state, nothing else happens.
            $this->stateStore->consume($provider, $state);

            return $this->refused($request, $loginUrl, 'social.error.failed');
        }

        // A test may check a disabled credential (before it is enabled); social login must still be on for the profile.
        $credential = $testing
            ? (($profile->socialLogin['mode'] ?? null) === SocialLoginMode::Enabled->value ? $this->credentials->findOneByProvider($provider) : null)
            : ($this->socialLoginGate->isEnabled($profile->name) ? $this->credentials->findOneByProvider($provider) : null);
        if (!$credential instanceof SocialLoginCredential || (!$testing && !$credential->isEnabled())) {
            return $handler instanceof SocialCallbackHandlerInterface && $this->burnsState($provider, $state)
                ? $this->failed($request, $provider, $handler, SocialCallbackFailure::Unavailable, $loginUrl)
                : new RedirectResponse($loginUrl);
        }
        // Only a form_post provider calls back with a POST; any other POST is not a callback.
        if ($posted && !$this->usesFormPost($credential)) {
            return new RedirectResponse($loginUrl);
        }

        // A denied/failed authorization comes back with `error` and no `code` (RFC 6749 §4.1.2.1):
        // handle it before the code/state check so users see the provider_denied message.
        if ($params->has('error')) {
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

        // GY-L9: the sign-in could not happen, so nothing may be linked for it. A handler signs in by itself.
        if (!$handler instanceof SocialCallbackHandlerInterface && !$this->loginAuthenticator->canSignIn($profile->firewall)) {
            return $this->refused($request, $loginUrl, 'social.error.failed');
        }

        try {
            $redirectUri = $this->urlGenerator->generate(
                $profile->routes['social_login_check']['name'],
                ['provider' => $provider],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            if ($this->oidcClient instanceof OidcClient && $this->oidcClient->supports($credential)) {
                // 2.3.0: code exchange, ID token verification (signature, iss, aud/azp, exp/iat, nonce), userinfo.
                $appleUser         = $params->get('user');
                [$tokens, $social] = $this->oidcClient->authenticate($credential, $code, $redirectUri, $pending['code_verifier'], $pending['nonce'], is_string($appleUser) ? $appleUser : null);
            } else {
                $tokens = $this->oauth2Client->exchangeCode($credential, $code, $redirectUri, $pending['code_verifier']);
                $social = $this->oauth2Client->fetchUserProfile($credential, $tokens['access_token']);
            }
            if (!$handler instanceof SocialCallbackHandlerInterface) {
                MfaFirstFactor::mark($request, 'social');
                // 2.7.0: the credential's own first-factor policy (amr read from the validated ID token only).
                MfaFirstFactor::markRule($request, SocialCredentialPolicy::mfaRule($credential, $social));
                // A failed sign-in keeps nothing: no link, no replaced password, no session (GY-L9, GY-L10).
                $this->guarded($request, fn () => $this->accountLinker->linkAndSignIn($profile, $provider, $social, $tokens, fn (UserInterface $user): ?Response => $this->security->login($user, $this->loginAuthenticator->nameFor($profile->firewall), $profile->firewall)));
            }
        } catch (TransientStorageFailure $failure) {
            // 1.50.0 (audit QG-L4): a deadlock or lock wait answers 409 (1.53.0, 503 per conflict_response) with its conflict code (StorageFailureSubscriber), never a
            // refusal; nothing was linked or signed in (the unit was rolled back).
            throw $failure;
        } catch (RuntimeException $exception) {
            $context = ['provider' => $provider, 'exception' => $exception::class];
            if ($exception instanceof OidcException) {
                $context['reason'] = $exception->reason;
            }
            $this->logger?->warning('AuthKit social callback failed for provider {provider}.', $context);

            return $handler instanceof SocialCallbackHandlerInterface
                ? $this->failed($request, $provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed')
                : $this->refused($request, $loginUrl, 'social.error.failed');
        }

        if ($handler instanceof SocialCallbackHandlerInterface) {
            return $this->handled($request, $handler, new SocialCallbackContext($profile, $provider, $social, $tokens), $loginUrl, SocialCredentialPolicy::mfaRule($credential, $social));
        }

        $target = $profile->loginSuccessRoute ?? $profile->routes['login']['name'];

        return new RedirectResponse($this->urlGenerator->generate($target));
    }

    /**
     * The provider's own cross-site POST: answered with the relay page when the provider is a form_post one, else sent
     * to the sign-in page. Nothing here reads or writes the session (2.3.0).
     */
    private function relayFormPost(Request $request, string $provider, ProfileSettings $profile, string $loginUrl): Response
    {
        $relay      = $this->formPostRelay;
        $credential = $relay instanceof SocialFormPostRelay && $this->socialLoginGate->isEnabled($profile->name)
            ? $this->credentials->findOneByProvider($provider)
            : null;
        if (!$relay instanceof SocialFormPostRelay || !$credential instanceof SocialLoginCredential || !$credential->isEnabled() || !$this->usesFormPost($credential)) {
            return new RedirectResponse($loginUrl);
        }

        return $relay->relay($request, $this->urlGenerator->generate($profile->routes['social_login_check']['name'], ['provider' => $provider]));
    }

    private function usesFormPost(SocialLoginCredential $credential): bool
    {
        return $this->oidcClient instanceof OidcClient && $this->oidcClient->usesFormPost($credential);
    }

    /**
     * The first handler that owns the callback: a {@see SocialCallbackStepInterface} through its gate (per call);
     * AuthKit's own invitation acceptance page through the internal handler interface.
     */
    private function handlerFor(Request $request, string $provider, ProfileSettings $profile): ?SocialCallbackHandlerInterface
    {
        foreach ($this->callbackHandlers as $handler) {
            if ($handler instanceof SocialCallbackStepInterface && !$handler instanceof SocialCallbackHandlerInterface) {
                $handler = new SocialCallbackStepGate($handler, $profile, $this->security, $this->loginAuthenticator);
            }
            if (!$handler instanceof SocialCallbackHandlerInterface) {
                throw new LogicException(sprintf('The AuthKit social callback handler "%s" must implement "%s".', get_debug_type($handler), SocialCallbackStepInterface::class));
            }
            if ($handler->supports($request, $provider)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * 2.7.0: the host's test handler (the first tagged {@see SocialLoginTestHandlerInterface::TAG}) through its gate,
     * or null when none is registered.
     */
    private function testHandlerFor(ProfileSettings $profile): ?SocialCallbackHandlerInterface
    {
        foreach ($this->testHandlers as $handler) {
            if ($handler instanceof SocialLoginTestHandlerInterface) {
                return new SocialLoginTestGate($handler, $profile);
            }
        }

        return null;
    }

    /**
     * Runs the handler. Whatever it throws, a sign-in it already made does not survive (GY-L1, GY-L6). HTTP exceptions
     * keep their status (the handler's own 403, 404, 429) and lock conflicts answer 409 (2.2.1); anything else takes the
     * failure path, logged by class because the message may hold provider data (GY-I1).
     */
    private function handled(Request $request, SocialCallbackHandlerInterface $handler, SocialCallbackContext $context, string $loginUrl, ?string $mfaRule): Response
    {
        MfaFirstFactor::mark($request, 'social');
        MfaFirstFactor::markRule($request, $mfaRule);

        try {
            return $this->guarded($request, static fn (): Response => $handler->handle($request, $context));
        } catch (Throwable $exception) {
            $this->logger?->error('AuthKit social callback handler {handler} failed for provider {provider}.', ['handler' => match (true) {
                $handler instanceof SocialCallbackStepGate => $handler->step()::class,
                $handler instanceof SocialLoginTestGate    => $handler->handler()::class,
                default                                    => $handler::class,
            }, 'provider' => $context->provider, 'exception' => $exception::class]);
            // 2.2.1 (REQ-RUNTIME-003 rules 6 and 8): a lock conflict (deadlock, lock wait, serialization failure, an
            // AuthKit named lock not obtained) is never turned into a refusal: it goes on to StorageFailureSubscriber,
            // which answers 409 with its conflict code, as on the built-in linker path.
            if ($exception instanceof HttpExceptionInterface || $this->isLockConflict($exception)) {
                throw $exception;
            }

            return $this->failed($request, $context->provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed');
        }
    }

    /**
     * Whether the failure is a lock conflict StorageFailureSubscriber answers (2.2.1).
     */
    private function isLockConflict(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return (new TransientFailureClassifier())->isTransient($exception);
    }

    /**
     * @template T
     *
     * @param callable(): T $signIn
     *
     * @return T
     */
    private function guarded(Request $request, callable $signIn): mixed
    {
        return $this->signInRollback instanceof SocialHandlerSignInRollback ? $this->signInRollback->guard($request, $signIn) : $signIn();
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
