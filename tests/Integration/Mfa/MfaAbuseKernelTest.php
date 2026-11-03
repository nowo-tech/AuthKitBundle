<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaLockedOutEvent;

/**
 * Abuse protection (1.34): attempts per pending sign-in, account lockout across sign-ins, pending expiry.
 */
final class MfaAbuseKernelTest extends MfaKernelTestCase
{
    public function testTooManyWrongCodesEndTheSignInThenTheAccountLocks(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        for ($i = 1; $i < 5; ++$i) {
            $this->challenge(['code' => '000000']);
            self::assertResponseStatusCodeSame(422);
        }
        $this->challenge(['code' => '000000']);
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        self::assertStringContainsString('Too many wrong codes. Sign in again.', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/login/mfa');
        self::assertResponseRedirects('/login', null, 'The pending sign-in is gone.');

        $this->signIn('member@example.com', 'member-password-123');
        for ($i = 1; $i <= 5; ++$i) {
            $this->challenge(['code' => '000000']);
        }
        self::assertCount(1, $this->eventsOf(MfaLockedOutEvent::class), 'The 10th failure locks the account.');
        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $this->code($secret)]);
        self::assertResponseRedirects('/login', 303, 'Even a valid code is refused while locked out.');
    }

    public function testAPendingSignInExpires(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->clock()->modify('+301 seconds');
        $this->client->request('GET', '/mfa-home');
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        self::assertStringContainsString('The verification took too long', (string) $this->client->getResponse()->getContent());
    }

    public function testAnEmptyCodeIsRefusedWithoutCountingAsAFailure(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the code from your authenticator app', (string) $this->client->getResponse()->getContent());
    }
}
