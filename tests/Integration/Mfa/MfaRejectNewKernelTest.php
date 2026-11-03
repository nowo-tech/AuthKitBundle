<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A completed second step respects UserKit's `reject_new` (1.34, audit DC-M1): parallel pending sign-ins cannot
 * exceed the concurrent-session limit.
 */
final class MfaRejectNewKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaRejectNewTestKernel::class;
    }

    public function testParallelPendingSignInsRespectTheLimit(): void
    {
        $secret = $this->manager()->newSecret();
        $this->manager()->enable($this->user('member@example.com'), $secret, $this->code($secret));
        $this->nextStep();

        $second = new KernelBrowser($this->client->getKernel());
        $second->disableReboot();
        $this->signIn('member@example.com', 'member-password-123');
        $this->signIn('member@example.com', 'member-password-123', client: $second);

        $this->challenge(['code' => $this->code($secret)]);
        $this->client->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $this->client->getResponse()->getContent());

        $this->nextStep();
        $this->challenge(['code' => $this->code($secret)], $second);
        $second->request('GET', '/mfa-home');
        self::assertTrue($second->getResponse()->isRedirect(), 'The second session is over the limit: signed out at completion.');
        $second->request('GET', '/mfa-home');
        self::assertSame('http://localhost/login', $second->getResponse()->headers->get('Location'));
    }
}
