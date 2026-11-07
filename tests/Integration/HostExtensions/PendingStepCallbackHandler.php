<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Throwable;

/**
 * What a host's "accept the invitation with Google" handler looks like: owns the callback while its session entry is
 * pending. Registered without an explicit tag (autoconfiguration). `/test/pending` sets the entry.
 */
final class PendingStepCallbackHandler implements SocialCallbackHandlerInterface
{
    public const SESSION_KEY = '_test_pending_step';

    /** What `handle()` throws, after signing in when `signInFirst` is set. */
    public ?Throwable $throw = null;

    public bool $signInFirst = false;

    public string $signInAs = 'ada@example.com';

    public bool $rememberMe = false;

    public bool $signOutFirst = false;

    public ?Closure $beforeSignIn = null;

    /** Table dropped after signing in: the rollback then fails on the database (GY-L8). */
    public ?string $dropTable = null;

    public bool $defaultOnFailure = false;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request, string $provider): bool
    {
        return $request->getSession()->has(self::SESSION_KEY);
    }

    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);
        if ($this->beforeSignIn instanceof Closure) {
            ($this->beforeSignIn)();
        }
        if ($this->signInFirst) {
            $user = $this->entityManager->getRepository(KernelUser::class)->findOneBy(['email' => $this->signInAs]);
            if (!$user instanceof KernelUser) {
                throw new LogicException('Persist the user first.');
            }
            $this->security->login($user, 'form_login', 'main', $this->rememberMe ? [(new RememberMeBadge())->enable()] : []);
        }
        if ($this->dropTable !== null) {
            $this->entityManager->getConnection()->executeStatement('DROP TABLE ' . $this->dropTable);
        }
        if ($this->signOutFirst) {
            $this->security->logout(false);
        }
        if ($this->throw instanceof Throwable) {
            throw $this->throw;
        }

        return new Response('handled|' . $context->profile->name . '|' . $context->provider . '|' . $context->socialProfile->email . '|' . $context->tokens['access_token'] . '|' . MfaFirstFactor::of($request));
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->defaultOnFailure ? null : new Response('failed|' . $provider . '|' . $reason->value);
    }
}
