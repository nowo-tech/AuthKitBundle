<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodeUsedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_replace;

/**
 * Audit DD (AuthKit #30) regressions: attempts consumed before verification, setup bound to its own pending sign-in,
 * re-authentication for the account page, password changes forget trusted devices without UserKit.
 */
final class MfaAuditDdKernelTest extends MfaKernelTestCase
{
    public function testAnAttemptIsConsumedBeforeTheCodeIsVerified(): void
    {
        $member = $this->user('member@example.com');
        $secret = $this->manager()->newSecret();
        $codes  = $this->manager()->enable($member, $secret, $this->code($secret));
        self::assertNotNull($codes);
        $limiter = self::getContainer()->get(AuthKitAttemptLimiter::class);
        self::assertInstanceOf(AuthKitAttemptLimiter::class, $limiter);
        $seen       = null;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MfaRecoveryCodeUsedEvent::class, static function () use ($limiter, &$seen): void {
            // Runs during the verification: the attempt must already be counted (a concurrent guess would be refused).
            $seen = $limiter->count('mfa:account:default:' . KernelUser::class . '#member@example.com');
        });
        self::assertSame(MfaCheck::Recovery, $this->manager()->check($this->user('member@example.com'), $codes[0], '10.0.0.1'));
        self::assertSame(1, $seen, 'DD-M1: consume() before verify, not isAllowed() … hit().');

        for ($i = 0; $i < 10; ++$i) {
            $this->manager()->check($this->user('member@example.com'), '000000', '10.0.0.1');
        }
        self::assertSame(MfaCheck::LockedOut, $this->manager()->check($this->user('member@example.com'), $this->code($secret, 1), '10.0.0.1'));
    }

    public function testSetupIsBoundToItsOwnPendingSignIn(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->client->request('GET', '/logout');
        $this->clock()->modify('+8 days');
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa/setup');
        $this->client->request('GET', '/login/mfa/setup');

        // Meanwhile the owner enrols from another browser.
        $secret = $this->manager()->newSecret();
        $this->manager()->enable($this->user('admin@example.com'), $secret, $this->code($secret));
        $this->client->request('GET', '/login/mfa/setup');
        self::assertResponseRedirects('/login', 303, 'DD-L1: no completion or re-enrolment over the owner.');
        $this->client->followRedirect();
        self::assertStringContainsString('changed in the meantime', (string) $this->client->getResponse()->getContent());
        self::assertSame($secret, $this->secretOf('admin@example.com'));
    }

    public function testSetupCodesAreLimitedPerPendingSignIn(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->client->request('GET', '/logout');
        $this->clock()->modify('+8 days');
        $this->signIn('admin@example.com', 'admin-password-123');
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $this->client->request('GET', '/login/mfa/setup');
            self::assertResponseIsSuccessful();
            $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => '000000']));
            self::assertResponseStatusCodeSame(422);
        }
        $crawler = $this->client->request('GET', '/login/mfa/setup');
        $secret  = str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text());
        $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => $this->code($secret)]));
        self::assertResponseRedirects('/login', 303);
    }

    public function testTheAccountPageNeedsAFreshSignIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123', remember: true);
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/account/security/mfa');
        self::assertResponseRedirects('http://localhost/login', null, 'DD-I1: remember-me sessions sign in again.');
    }

    public function testAPasswordChangeForgetsTrustedDevicesWithoutUserKit(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);

        $member = $this->user('member@example.com');
        $member->setPassword('new-member-password-123');
        $this->em()->flush();
        $forgotten = $this->eventsOf(MfaTrustedDevicesForgottenEvent::class);
        self::assertSame('password_changed', $forgotten[0]->reason);
    }
}
