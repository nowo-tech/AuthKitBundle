<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml\Controller;

use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Port\SocialCallbackStepGate;
use Nowo\AuthKitBundle\Port\SocialLoginTestGate;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SamlProviderRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Saml\Event\SamlResponseRejectedEvent;
use Nowo\AuthKitBundle\Saml\SamlFailure;
use Nowo\AuthKitBundle\Saml\SamlLoginStart;
use Nowo\AuthKitBundle\Saml\SamlPostRelay;
use Nowo\AuthKitBundle\Saml\SamlProfileMapper;
use Nowo\AuthKitBundle\Saml\SamlProviderPolicy;
use Nowo\AuthKitBundle\Saml\SamlRequestStore;
use Nowo\AuthKitBundle\Saml\SamlResponseValidator;
use Nowo\AuthKitBundle\Saml\SamlUrls;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\LoginHint;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\AuthKitBundle\SocialLogin\SocialHandlerSignInRollback;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
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
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function get_debug_type;
use function in_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * SP-initiated SAML 2.0 sign-in (2.10.0): the start (signed AuthnRequest, HTTP-Redirect) and the Assertion Consumer
 * Service (HTTP-POST). After every check of {@see SamlResponseValidator} and {@see SamlProfileMapper}, the identity
 * `saml:<provider>` takes the same path as an OpenID Connect callback: the test handler for a test sign-in, else the
 * first host callback step that supports it, else {@see SocialAccountLinker::linkAndSignIn()} (linking, just-in-time
 * creation, named locks; a lock conflict answers 409 with its conflict code through `StorageFailureSubscriber`, the
 * host entity manager is never cleared, reset or closed). Refusals are logged by reason code only and dispatched as
 * {@see SamlResponseRejectedEvent}. Stateless (worker mode): per-request data lives in the request and its session.
 */
final class SamlLoginController
{
    /** Route requirement of `{provider}` (the provider key pattern of {@see SamlProvider}). */
    public const PROVIDER_REQUIREMENT = '[a-z0-9_\-]{1,59}';

    /** The token answer of a SAML sign-in: SAML has none (the link stores no access token). */
    private const NO_TOKENS = ['access_token' => '', 'refresh_token' => null, 'expires_in' => null, 'raw' => []];

    /**
     * @param iterable<mixed> $callbackHandlers tagged `nowo_auth_kit.social_callback_handler`
     * @param iterable<mixed> $testHandlers tagged {@see SocialLoginTestHandlerInterface::TAG}
     */
    public function __construct(
        private readonly RequestProfileResolver $profileResolver,
        private readonly SamlProviderRepository $providers,
        private readonly SamlLoginStart $loginStart,
        private readonly SamlRequestStore $requestStore,
        private readonly SamlResponseValidator $validator,
        private readonly SamlProfileMapper $mapper,
        private readonly SamlUrls $urls,
        private readonly SamlPostRelay $relay,
        private readonly SocialAccountLinker $accountLinker,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly Security $security,
        private readonly AuthKitFlashMessenger $flashMessenger = new AuthKitFlashMessenger(),
        private readonly iterable $callbackHandlers = [],
        private readonly iterable $testHandlers = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SocialHandlerSignInRollback $signInRollback = null,
        private readonly ProgrammaticLoginAuthenticator $loginAuthenticator = new ProgrammaticLoginAuthenticator(),
        private readonly ?AuthKitAttemptLimiter $attemptLimiter = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?RpLogoutSession $rpLogout = null,
    ) {
    }

    #[Route(path: '/login/saml/{provider}', name: 'nowo_auth_kit_saml_login_start', requirements: ['provider' => self::PROVIDER_REQUIREMENT], methods: ['GET'])]
    public function start(Request $request, string $provider): RedirectResponse
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        if (!$profile->samlEnabled()) {
            return new RedirectResponse($loginUrl);
        }
        $limit = $profile->saml['start_rate_limit'] ?? 20;
        if ($this->attemptLimiter instanceof AuthKitAttemptLimiter && is_int($limit) && $limit > 0 && !$this->attemptLimiter->consume(
            'saml_start:' . $profile->name . ':' . ($request->getClientIp() ?? 'unknown'),
            $limit,
            is_int($window = $profile->saml['start_rate_window'] ?? 60) ? $window : 60,
        )) {
            return $this->refused($request, $loginUrl, 'social.error.rate_limited');
        }

        $row = $this->providers->findOneByProvider($provider);
        if (!$row instanceof SamlProvider || !$row->isEnabled()) {
            return new RedirectResponse($loginUrl);
        }

        try {
            return new RedirectResponse($this->loginStart->url($request, $profile, $row, LoginHint::normalize($request->query->all()[LoginHint::PARAMETER] ?? null)));
        } catch (SamlFailure $failure) {
            $this->logger?->warning('AuthKit SAML sign-in could not start for provider {provider} ({reason}).', ['provider' => $provider, 'reason' => $failure->reason]);

            return $this->refused($request, $loginUrl, 'social.error.failed');
        }
    }

    /**
     * The IdP's cross-site POST is relayed first ({@see SamlPostRelay}); the relayed same-site POST is checked.
     */
    #[Route(path: '/login/saml/{provider}/acs', name: 'nowo_auth_kit_saml_acs', requirements: ['provider' => self::PROVIDER_REQUIREMENT], methods: ['POST'])]
    public function acs(Request $request, string $provider): Response
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        if (!$profile->samlEnabled()) {
            return new RedirectResponse($loginUrl);
        }
        if (!SocialFormPostRelay::isRelayed($request)) {
            // No session, no flash, no handler here: the visitor's session cookie did not come with this request.
            return $this->relay->relay($request, $request->getBaseUrl() . $request->getPathInfo());
        }

        $relayState = $request->request->get('RelayState');
        $relayState = is_string($relayState) ? $relayState : '';
        $session    = $request->getSession();
        $account    = SamlProvider::accountProvider($provider);
        // A test sign-in is known only from the marker stored with this session's pending request (SamlTestStart).
        $testing = $this->requestStore->isTestPending($session, $profile->name, $provider, $relayState);
        $handler = $testing ? $this->testHandlerFor($profile) : $this->handlerFor($request, $account, $profile);
        $row     = $this->providers->findOneByProvider($provider);

        try {
            if (($testing && !$handler instanceof SocialCallbackHandlerInterface) || !$row instanceof SamlProvider || (!$testing && !$row->isEnabled()) || !$row->isComplete()) {
                // Burn a matching pending request, so it cannot be used once the provider comes back.
                $this->requestStore->consume($session, $profile->name, $provider, $relayState);

                throw new SamlFailure(SamlFailure::UNAVAILABLE, 'The SAML provider is unknown, disabled or not completely set up.');
            }
            $pending = $this->requestStore->consume($session, $profile->name, $provider, $relayState);
            // Defence in depth: the burned request must be of the mode chosen above.
            if ($pending['test'] !== $testing) {
                throw new SamlFailure(SamlFailure::INVALID_STATE, 'The pending SAML sign-in changed mode.'); // @codeCoverageIgnore
            }
            $samlResponse = $request->request->get('SAMLResponse');
            $assertion    = $this->validator->validate(
                $row,
                is_string($samlResponse) ? $samlResponse : '',
                $pending['request_id'],
                $this->urls->acsUrl($profile, $provider),
                $this->urls->spEntityId($profile, $row),
                is_int($skew = $profile->saml['clock_skew'] ?? 60) ? $skew : 60,
            );
            $social = $this->mapper->map($row, $assertion);
        } catch (SamlFailure $failure) {
            return $this->rejected($request, $profile, $provider, $handler, $failure, $loginUrl);
        }

        $rule        = SamlProviderPolicy::mfaRule($row, $assertion->authnContextClassRefs);
        $tokenBefore = $this->security->getToken();
        if ($handler instanceof SocialCallbackHandlerInterface) {
            $response = $this->handled($request, $handler, new SocialCallbackContext($profile, $account, $social, self::NO_TOKENS), $loginUrl, $rule);
            if (!$testing) {
                $this->forgetRpLogout($request, $tokenBefore);
            }

            return $response;
        }

        // The sign-in could not happen, so nothing may be linked for it.
        if (!$this->loginAuthenticator->canSignIn($profile->firewall)) {
            return $this->refused($request, $loginUrl, 'social.error.failed');
        }
        MfaFirstFactor::mark($request, 'social');
        MfaFirstFactor::markRule($request, $rule);

        try {
            // A failed sign-in keeps nothing: no link, no new account, no replaced password, no session.
            $this->guarded($request, fn () => $this->linkAndSignIn($profile, $account, $social));
        } catch (TransientStorageFailure $failure) {
            // REQ-RUNTIME-003 rule 6: a deadlock, lock wait or named lock not obtained answers 409 (CONFLICT_001 with
            // Retry-After, or CONFLICT_002 once part was committed) through StorageFailureSubscriber, never a refusal.
            throw $failure;
        } catch (RuntimeException $exception) {
            $this->logger?->warning('AuthKit SAML sign-in failed for provider {provider}.', ['provider' => $provider, 'exception' => $exception::class]);

            return $this->refused($request, $loginUrl, 'social.error.failed');
        }
        $this->forgetRpLogout($request, $tokenBefore);

        return new RedirectResponse($this->urlGenerator->generate($profile->loginSuccessRoute ?? $profile->routes['login']['name']));
    }

    private function linkAndSignIn(ProfileSettings $profile, string $account, SocialUserProfile $social): void
    {
        $this->accountLinker->linkAndSignIn($profile, $account, $social, self::NO_TOKENS, fn (UserInterface $user): ?Response => $this->security->login($user, $this->loginAuthenticator->nameFor($profile->firewall), $profile->firewall));
    }

    /**
     * A SAML sign-in replaces the session's OpenID Connect RP-initiated logout hint of an earlier sign-in (SAML Single
     * Logout is not offered): the sign-out after it is local only.
     */
    private function forgetRpLogout(Request $request, ?TokenInterface $tokenBefore): void
    {
        $token = $this->security->getToken();
        if ($this->rpLogout instanceof RpLogoutSession && $token instanceof TokenInterface && $token !== $tokenBefore) {
            $this->rpLogout->forget($request->getSession());
        }
    }

    /**
     * Logs and dispatches the refusal; a refusal after the pending request was proved goes to the handler's failure
     * answer, any other one (unsolicited, crafted RelayState) only to the flash and the login page.
     */
    private function rejected(Request $request, ProfileSettings $profile, string $provider, ?SocialCallbackHandlerInterface $handler, SamlFailure $failure, string $loginUrl): Response
    {
        $this->logger?->warning('AuthKit SAML sign-in refused for provider {provider} ({reason}).', ['provider' => $provider, 'reason' => $failure->reason]);
        $this->dispatcher?->dispatch(new SamlResponseRejectedEvent($profile->name, $provider, $failure->reason));

        if (in_array($failure->reason, [SamlFailure::UNSOLICITED, SamlFailure::INVALID_STATE], true)) {
            return $this->refused($request, $loginUrl, 'social.error.invalid_state');
        }
        if ($handler instanceof SocialCallbackHandlerInterface) {
            $reason   = $failure->reason === SamlFailure::UNAVAILABLE ? SocialCallbackFailure::Unavailable : SocialCallbackFailure::Failed;
            $response = $handler->handleFailure($request, SamlProvider::accountProvider($provider), $reason);
            if ($response instanceof Response) {
                return $response;
            }
        }

        return $this->refused($request, $loginUrl, 'social.error.failed');
    }

    /**
     * The first handler that owns the callback of `saml:<provider>`: a {@see SocialCallbackStepInterface} through its
     * gate (per call), or another internal handler.
     */
    private function handlerFor(Request $request, string $account, ProfileSettings $profile): ?SocialCallbackHandlerInterface
    {
        foreach ($this->callbackHandlers as $handler) {
            if ($handler instanceof SocialCallbackStepInterface && !$handler instanceof SocialCallbackHandlerInterface) {
                $handler = new SocialCallbackStepGate($handler, $profile, $this->security, $this->loginAuthenticator);
            }
            if (!$handler instanceof SocialCallbackHandlerInterface) {
                throw new LogicException(sprintf('The AuthKit social callback handler "%s" must implement "%s".', get_debug_type($handler), SocialCallbackStepInterface::class));
            }
            if ($handler->supports($request, $account)) {
                return $handler;
            }
        }

        return null;
    }

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
     * Runs the handler. Whatever it throws, a sign-in it already made does not survive. HTTP exceptions keep their
     * status and lock conflicts answer 409; anything else takes the failure path, logged by class only.
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
            if ($exception instanceof HttpExceptionInterface || $this->isLockConflict($exception)) {
                throw $exception;
            }
            $response = $handler->handleFailure($request, $context->provider, SocialCallbackFailure::Failed);

            return $response instanceof Response ? $response : $this->refused($request, $loginUrl, 'social.error.failed');
        }
    }

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

    private function refused(Request $request, string $loginUrl, string $flash): RedirectResponse
    {
        $this->flashMessenger->flash($request, 'error', $flash);

        return new RedirectResponse($loginUrl);
    }
}
