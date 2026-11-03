<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDevicesForgottenEvent;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\UserKitBundle\Sessions\Entity\UserSession;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * MFA with UserKit sessions (1.34): a pending sign-in is registered only once complete, disabling MFA signs the
 * other sessions out, "sign out everywhere" forgets trusted devices, the UserKit trusted-device limit, user removal,
 * and `remember_me: challenge`.
 */
final class MfaSessionsKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaSessionsTestKernel::class;
    }

    public function testAPendingSignInIsRegisteredOnceComplete(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $before = $this->rows('member@example.com');

        $this->signIn('member@example.com', 'member-password-123');
        self::assertSame($before, $this->rows('member@example.com'), 'Nothing is registered while the code is pending.');
        $this->challenge(['code' => $this->code($secret)]);
        $this->client->request('GET', '/mfa-home');
        self::assertSame($before + 1, $this->rows('member@example.com'));
    }

    public function testDisablingSignsTheOtherSessionsOutAndEverywhereForgetsDevices(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');

        $other = new KernelBrowser($this->client->getKernel());
        $other->disableReboot();
        $this->signIn('member@example.com', 'member-password-123', client: $other);
        $this->nextStep();
        $this->challenge(['code' => $this->code($secret)], $other);
        $other->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $other->getResponse()->getContent());

        $this->client->getCookieJar()->clear();
        $this->signIn('member@example.com', 'member-password-123');
        $this->nextStep();
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);
        $this->nextStep();
        $crawler = $this->client->request('GET', '/account/security/mfa');
        $this->client->submit($crawler->filter('form[name="mfa_disable"]')->form(['mfa_disable[code]' => $this->code($secret)]));
        $other->request('GET', '/mfa-home');
        self::assertSame('http://localhost/login', $other->getResponse()->headers->get('Location'), 'The other session was signed out.');
        self::assertSame('disabled', $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[0]->reason);
    }

    public function testSignOutEverywhereForgetsTrustedDevicesAndTheLimitComesFromUserKit(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        $this->client->setServerParameter('HTTP_X_DEVICE', 'phone-1');
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);
        $this->client->request('GET', '/logout');

        $this->client->getCookieJar()->clear();
        $this->signIn('member@example.com', 'member-password-123');
        $this->nextStep();
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true]);
        self::assertSame('limit', $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[0]->reason, 'UserKit allows one trusted device.');

        $sessions = self::getContainer()->get(UserSessionManager::class);
        self::assertInstanceOf(UserSessionManager::class, $sessions);
        $sessions->revokeAll($this->user('member@example.com'));
        self::assertSame('sessions', $this->eventsOf(MfaTrustedDevicesForgottenEvent::class)[1]->reason);

        $sessions->forgetUser($this->user('member@example.com'));
        self::assertNull($this->em()->getRepository(MfaCredential::class)->findOneBy(['userId' => 'member@example.com']));
    }

    public function testRememberMeIsChallengedInChallengeMode(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();
        $this->signIn('member@example.com', 'member-password-123', remember: true);
        $this->challenge(['code' => $this->code($secret)]);
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        self::assertNull($this->client->getCookieJar()->get(MfaTrustedDevices::COOKIE));

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/mfa-home');
        self::assertResponseRedirects('/login/mfa', null, 'A remember-me sign-in is challenged in challenge mode.');
    }

    public function testTheHostPolicyAndForgettingADeviceSignsItsSessionsOut(): void
    {
        self::assertSame(MfaRequirement::Required, $this->manager()->requirement($this->user('support@example.com')));
        self::assertSame(MfaRequirement::Optional, $this->manager()->requirement($this->user('member@example.com')));

        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $phone    = new KernelBrowser($this->client->getKernel());
        $phone->disableReboot();
        $phone->setServerParameter('HTTP_X_DEVICE', 'phone-1');
        $this->signIn('member@example.com', 'member-password-123', client: $phone);
        $this->challenge(['code' => $this->code($secret), 'trust_device' => true], $phone);
        $phone->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $phone->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/account/security/mfa');
        $this->client->submit($crawler->filter('[data-testid="auth-kit-mfa-forget"]')->form());
        $phone->request('GET', '/mfa-home');
        self::assertSame('http://localhost/login', $phone->getResponse()->headers->get('Location'), 'Forgetting the device signed its sessions out (UserKit revokeDevice).');
        $this->client->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $this->client->getResponse()->getContent(), 'The browser that forgot it stays signed in.');
    }

    private function rows(string $email): int
    {
        $this->em()->clear();

        return $this->em()->getRepository(UserSession::class)->count(['userId' => $email]);
    }
}
