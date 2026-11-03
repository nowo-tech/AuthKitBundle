<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;

/**
 * Audit DD follow-up (AuthKit #30 re-audit): a recent sign-in before setting up the second factor (DD-I1), wrong codes
 * only count per IP (DD-I7), a configurable password field (DD-I6).
 */
final class MfaAuditDdFollowUpKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaPasswordFieldTestKernel::class;
    }

    public function testSettingUpTheSecondFactorAsksForARecentSignIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->clock()->modify('+16 minutes');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="auth-kit-mfa-sign-in-again"]'));
        self::assertCount(0, $crawler->filter('[data-testid="auth-kit-mfa-secret"]'), 'No secret for an old session.');
        $this->client->request('POST', '/account/security/mfa/enable', ['mfa_enable' => ['code' => '123456']]);
        self::assertResponseRedirects();
        self::assertFalse($this->manager()->isEnabled($this->user('member@example.com')));

        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        self::assertTrue($this->manager()->isEnabled($this->user('member@example.com')));
    }

    public function testOnlyWrongCodesCountPerIp(): void
    {
        $member = $this->user('member@example.com');
        $secret = $this->manager()->newSecret();
        self::assertNotNull($this->manager()->enable($member, $secret, $this->code($secret)));
        $this->nextStep();
        $limiter = self::getContainer()->get(AuthKitAttemptLimiter::class);
        self::assertInstanceOf(AuthKitAttemptLimiter::class, $limiter);

        self::assertSame(MfaCheck::Totp, $this->manager()->check($member, $this->code($secret), '10.0.0.9'));
        self::assertSame(0, $limiter->count('mfa:ip:10.0.0.9'), 'DD-I7: a valid code gives its slot back.');
        self::assertSame(MfaCheck::Invalid, $this->manager()->check($member, '000000', '10.0.0.9'));
        self::assertSame(1, $limiter->count('mfa:ip:10.0.0.9'));
    }

    public function testTheConfiguredPasswordFieldForgetsTrustedDevices(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);

        $member = $this->user('member@example.com');
        $member->setPassword('new-member-password-123');
        $this->em()->flush();
        self::assertCount(0, $this->eventsOf(MfaTrustedDevicesForgottenEvent::class), 'Not the configured field.');
        $member->setNickname('renamed');
        $this->em()->flush();
        self::assertSame('password_changed', $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[0]->reason);
    }
}
