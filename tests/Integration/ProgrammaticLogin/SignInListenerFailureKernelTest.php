<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use LogicException;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * GY-L9 and GY-L10: a `LoginSuccessEvent` listener throws after the token is set. Nobody stays signed in as that account,
 * an earlier session goes on, and the link and password replacement are rolled back.
 */
final class SignInListenerFailureKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return FormLoginAndLoginLinkKernel::class;
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function failures(): iterable
    {
        yield 'runtime' => [new RuntimeException('listener down'), 302];
        yield 'logic' => [new LogicException('listener down'), 500];
    }

    #[DataProvider('failures')]
    public function testSocialLeavesNothing(Throwable $failure, int $status): void
    {
        $this->listener()->throw = $failure;

        $this->socialCallback();

        self::assertResponseStatusCodeSame($status);
        $this->listener()->throw = null;
        self::assertNull($this->token());
        $this->assertAdaKeepsHerPasswordAndHasNoLink();
        $this->assertAnonymousOnNextRequest();
    }

    #[DataProvider('failures')]
    public function testQrLeavesNobodySignedIn(Throwable $failure, int $status): void
    {
        $this->listener()->throw = $failure;

        $this->qrCompletion();

        self::assertResponseStatusCodeSame($status);
        $this->listener()->throw = null;
        self::assertNull($this->token());
        $this->assertAnonymousOnNextRequest();
    }

    /** HP-I3: what the rollback undid must not stay in memory, where the next read in this process would find it. */
    public function testAFailureLeavesNoRolledBackStateInTheEntityManager(): void
    {
        $user                    = $this->user();
        $this->listener()->throw = new RuntimeException('listener down');

        $this->socialCallback();

        $this->listener()->throw = null;
        self::assertFalse($this->em()->contains($user), 'Every entity of the request is detached.');
        self::assertSame('old-password-123', $this->user()->getPassword());
    }

    public function testSocialKeepsAnEarlierSession(): void
    {
        $this->signInAsOther();
        $this->listener()->throw = new RuntimeException('listener down');

        $this->socialCallback();

        self::assertResponseRedirects('/login');
        $this->listener()->throw = null;
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
        $this->assertAdaKeepsHerPasswordAndHasNoLink();
        $this->client->request('GET', '/home');
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
    }

    public function testQrKeepsAnEarlierSession(): void
    {
        $this->signInAsOther();
        $this->listener()->throw = new RuntimeException('listener down');

        $this->qrCompletion();

        self::assertResponseRedirects('/login');
        $this->listener()->throw = null;
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
    }

    public function testRegistrationLeavesNobodySignedIn(): void
    {
        $this->listener()->throw = new LogicException('listener down');

        $this->submitRegistration('/register', 'new@example.com');

        self::assertResponseStatusCodeSame(500);
        self::assertNull($this->token());
    }

    public function testInvitationLeavesNobodySignedIn(): void
    {
        $path                    = $this->invitationPath('invited@example.com');
        $this->listener()->throw = new LogicException('listener down');

        $this->submitRegistration($path, 'invited@example.com');

        self::assertResponseStatusCodeSame(500);
        self::assertNull($this->token());
    }

    private function assertAnonymousOnNextRequest(): void
    {
        $this->client->request('GET', '/home');
        self::assertNull($this->token());
    }

    private function signInAsOther(): void
    {
        $other = (new KernelUser())->setEmail('other@example.com')->setPassword('other-password-123');
        $this->em()->persist($other);
        $this->em()->flush();
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', '/home');
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
    }

    private function listener(): ThrowingLoginListener
    {
        $listener = self::getContainer()->get(ThrowingLoginListener::class);
        self::assertInstanceOf(ThrowingLoginListener::class, $listener);

        return $listener;
    }
}
