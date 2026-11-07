<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Registration and invitations on a firewall without `form_login`: 500 with the account created up to 1.37.1.
 */
final class AccountFlowsOnlyLoginLinkKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OnlyLoginLinkKernel::class;
    }

    public function testRegistrationSignsIn(): void
    {
        $this->submitRegistration('/register', 'new@example.com');

        self::assertResponseRedirects();
        $this->assertSignedInAs('new@example.com');
    }

    public function testInvitationSignsIn(): void
    {
        $this->submitRegistration($this->invitationPath('invited@example.com'), 'invited@example.com');

        self::assertResponseRedirects();
        $this->assertSignedInAs('invited@example.com');
    }

    private function assertSignedInAs(string $email): void
    {
        $token = $this->token();
        self::assertInstanceOf(TokenInterface::class, $token);
        self::assertInstanceOf(PostAuthenticationToken::class, $token);
        self::assertSame($email, $token->getUserIdentifier());
    }
}
