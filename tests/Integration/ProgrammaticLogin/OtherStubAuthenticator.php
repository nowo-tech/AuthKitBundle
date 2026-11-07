<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * A host's own authenticator: it never authenticates a request by itself here.
 */
final class OtherStubAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new LogicException('Not used.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
