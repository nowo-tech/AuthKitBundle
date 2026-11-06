<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a host's "accept the invitation with Google" handler looks like: owns the callback while its session entry is
 * pending. Registered without an explicit tag (autoconfiguration). `/test/pending` sets the entry.
 */
final class PendingStepCallbackHandler implements SocialCallbackHandlerInterface
{
    public const SESSION_KEY = '_test_pending_step';

    public bool $throwOnHandle = false;

    public bool $defaultOnFailure = false;

    public function supports(Request $request, string $provider): bool
    {
        return $request->getSession()->has(self::SESSION_KEY);
    }

    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);
        if ($this->throwOnHandle) {
            throw new RuntimeException('refused');
        }

        return new Response('handled|' . $context->profile->name . '|' . $context->provider . '|' . $context->socialProfile->email . '|' . $context->tokens['access_token'] . '|' . MfaFirstFactor::of($request));
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->defaultOnFailure ? null : new Response('failed|' . $provider . '|' . $reason->value);
    }
}
