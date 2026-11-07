<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use RuntimeException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * HP-L3: a `LoginSuccessEvent` listener throws during the magic link confirm, with the same guard as the other flows.
 */
final class MagicConfirmListenerFailureKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MagicConfirmKernel::class;
    }

    public function testASuccessfulConfirmSignsIn(): void
    {
        $this->confirm();

        self::assertResponseRedirects();
        self::assertSame('ada@example.com', $this->token()?->getUserIdentifier());
    }

    public function testAFailureLeavesNobodySignedIn(): void
    {
        $this->listener()->throw = new RuntimeException('listener down');

        $this->confirm();

        self::assertResponseStatusCodeSame(500);
        $this->listener()->throw = null;
        self::assertNull($this->token());
        $this->client->request('GET', '/home');
        self::assertNull($this->token());
    }

    public function testAFailureKeepsAnEarlierSession(): void
    {
        $other = (new KernelUser())->setEmail('other@example.com')->setPassword('other-password-123');
        $this->em()->persist($other);
        $this->em()->flush();
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', '/home');
        $this->listener()->throw = new RuntimeException('listener down');

        $this->confirm();

        self::assertResponseStatusCodeSame(500);
        $this->listener()->throw = null;
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
        $this->client->request('GET', '/home');
        $this->assertStillOther();
    }

    private function assertStillOther(): void
    {
        self::assertSame('other@example.com', $this->token()?->getUserIdentifier());
    }

    private function confirm(): void
    {
        $handler = self::getContainer()->get('security.authenticator.login_link_handler.main');
        self::assertInstanceOf(LoginLinkHandlerInterface::class, $handler);
        $crawler = $this->client->request('GET', $handler->createLoginLink($this->user())->getUrl());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form')->first()->form());
    }

    private function listener(): ThrowingLoginListener
    {
        $listener = self::getContainer()->get(ThrowingLoginListener::class);
        self::assertInstanceOf(ThrowingLoginListener::class, $listener);

        return $listener;
    }
}
