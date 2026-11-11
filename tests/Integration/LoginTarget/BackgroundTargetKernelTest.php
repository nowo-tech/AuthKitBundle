<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

/**
 * Default configuration (expired-session 401 off): the firewall still answers background calls with its 302, but they
 * never replace the saved page, and a machine target never survives the sign-in.
 */
final class BackgroundTargetKernelTest extends LoginTargetKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return LoginTargetPreserveTestKernel::class;
    }

    public function testPollAfterThePageKeepsThePageAsTarget(): void
    {
        $this->navigate('/secret');
        $this->fetch('/api/poll');
        self::assertResponseRedirects('http://localhost/login');
        self::assertSame('http://localhost/secret', $this->savedTarget());

        $this->signIn();
        self::assertResponseRedirects('http://localhost/secret');
    }

    public function testPollAloneSavesNothing(): void
    {
        $this->fetch('/api/poll');
        self::assertResponseRedirects('http://localhost/login');
        self::assertNull($this->savedTarget());

        $this->signIn();
        self::assertResponseRedirects('/home');
    }
}
