<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;

/**
 * Host callback step of the SAML kernel tests: owns `saml:stepped` only, records what it gets; `throw` makes it fail.
 */
final class RecordingSamlStep implements SocialCallbackStepInterface
{
    /** @var list<SocialCallbackContext> */
    public array $handled = [];

    /** @var list<SocialCallbackFailure> */
    public array $failures = [];

    public ?\Throwable $throw = null;

    public function supports(AuthRequestContext $request, string $provider): bool
    {
        return $provider === 'saml:stepped';
    }

    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult
    {
        if ($this->throw instanceof \Throwable) {
            throw $this->throw;
        }
        $this->handled[] = $context;

        return SocialCallbackResult::redirect('/host/linking');
    }

    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult
    {
        $this->failures[] = $reason;

        return SocialCallbackResult::redirect('/host/saml-failed');
    }
}
