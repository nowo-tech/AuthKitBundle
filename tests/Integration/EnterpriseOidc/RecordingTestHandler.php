<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;

/**
 * Host test handler of the kernel tests (2.7.0): records what it is given; `signIn` makes it (wrongly) ask for a sign-in.
 */
final class RecordingTestHandler implements SocialLoginTestHandlerInterface
{
    /** @var list<SocialCallbackContext> */
    public array $handled = [];

    /** @var list<SocialCallbackFailure> */
    public array $failures = [];

    public ?AuthUserRef $signIn = null;

    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult
    {
        $this->handled[] = $context;

        return $this->signIn instanceof AuthUserRef ? SocialCallbackResult::signIn($this->signIn, '/home') : SocialCallbackResult::redirect('/admin/sso-test-result');
    }

    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): SocialCallbackResult
    {
        $this->failures[] = $reason;

        return $this->signIn instanceof AuthUserRef ? SocialCallbackResult::signIn($this->signIn, '/home') : SocialCallbackResult::redirect('/admin/sso-test-failed');
    }
}
