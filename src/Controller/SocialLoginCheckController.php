<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Controller;

use LogicException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Port\SocialCallbackStepGate;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
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
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function get_debug_type;
use function sprintf;

/**
 * Completes the OAuth authorization-code callback and logs the user in. A host
 * {@see SocialCallbackHandlerInterface} can own the last step (1.37): every check below still runs first.
 */
final class SocialLoginCheckController
{
    /**
     * @param iterable<mixed> $callbackHandlers tagged `nowo_auth_kit.social_callback_handler` (either interface; checked at build time)
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
    ) {
    }

    #[Route(path: '/login/social/{provider}/check', name: 'nowo_auth_kit_social_login_check', requirements: ['provider' => '[a-z0-9_\\-]+'], methods: ['GET'])]
    public function check(Request $request, string $provider): Response
    {
        $profile  = $this->profileResolver->resolve($request);
        $loginUrl = $this->urlGenerator->generate($profile->routes['login']['name']);
        $handler  = $this->handlerFor($request, $provider, $profile);
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
            $tokens = $this->oauth2Client->exchangeCode($credential, $code, $redirectUri, $pending['code_verifier']);
            $social = $this->oauth2Client->fetchUserProfile($credential, $tokens['access_token']);
            if (!$handler instanceof SocialCallbackHandlerInterface) {
                MfaFirstFactor::mark($request, 'social');
                // A failed sign-in keeps nothing: no link, no replaced password, no session (GY-L9, GY-L10).
                $this->guarded($request, fn () => $this->accountLinker->linkAndSignIn($profile, $provider, $social, $tokens, fn (UserInterface $user): ?Response => $this->security->login($user, $this->loginAuthenticator->nameFor($profile->firewall), $profile->firewall)));
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

    /**
     * The first handler that owns the callback. 1.46.0: a handler implementing the deprecated interface is asked as in
     * 1.45; one implementing only {@see SocialCallbackStepInterface} through its gate (per call).
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
     * Runs the handler. Whatever it throws, a sign-in it already made does not survive (GY-L1, GY-L6). HTTP exceptions
     * keep their status (the handler's own 403, 404, 429); anything else takes the failure path, logged by class because
     * the message may hold provider data (GY-I1).
     */
    private function handled(Request $request, SocialCallbackHandlerInterface $handler, SocialCallbackContext $context, string $loginUrl): Response
    {
        MfaFirstFactor::mark($request, 'social');

        try {
            return $this->guarded($request, static fn (): Response => $handler->handle($request, $context));
        } catch (Throwable $exception) {
            $this->logger?->error('AuthKit social callback handler {handler} failed for provider {provider}.', ['handler' => $handler instanceof SocialCallbackStepGate ? $handler->step()::class : $handler::class, 'provider' => $context->provider, 'exception' => $exception::class]);
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->failed($request, $context->provider, $handler, SocialCallbackFailure::Failed, $loginUrl, 'social.error.failed');
        }
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
