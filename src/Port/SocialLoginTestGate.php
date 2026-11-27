<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use LogicException;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks the host's {@see SocialLoginTestHandlerInterface} (2.7.0) where the social callback asks its internal
 * {@see SocialCallbackHandlerInterface}, for a callback whose state carries the test marker. Never signs anyone in: a
 * sign-in result from `handle()` throws (failure path), one from `handleFailure()` is ignored. Built per call.
 *
 * @internal
 */
final readonly class SocialLoginTestGate implements SocialCallbackHandlerInterface
{
    public function __construct(
        private SocialLoginTestHandlerInterface $handler,
        private ProfileSettings $profile,
    ) {
    }

    public function handler(): SocialLoginTestHandlerInterface
    {
        return $this->handler;
    }

    /** The controller chose this gate from the server-side test marker; there is nothing else to decide. */
    public function supports(Request $request, string $provider): bool
    {
        return true;
    }

    /**
     * @throws LogicException when the handler asks for a sign-in
     */
    public function handle(Request $request, SocialCallbackContext $context): RedirectResponse
    {
        $result = $this->handler->handle($this->context($request), $context);
        if ($result->signIn instanceof AuthUserRef) {
            throw new LogicException('A social test sign-in never signs anyone in: answer with SocialCallbackResult::redirect().');
        }

        return new RedirectResponse($result->redirectUrl);
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        $result = $this->handler->handleFailure($this->context($request), $provider, $reason);

        return $result instanceof SocialCallbackResult && !$result->signIn instanceof AuthUserRef ? new RedirectResponse($result->redirectUrl) : null;
    }

    private function context(Request $request): AuthRequestContext
    {
        return AuthRequestContexts::fromRequest($request, $this->profile->firewall, $this->profile->name);
    }
}
