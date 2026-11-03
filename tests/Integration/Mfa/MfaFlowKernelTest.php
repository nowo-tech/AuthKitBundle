<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaChallengeFailedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaEnabledEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaLoginCompletedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodeUsedEvent;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * MFA sign-in through the real kernel (1.34): enrolment on the account page, the pending token kept out of the app,
 * TOTP and recovery codes, replay, the original target, remember-me only after the second step.
 */
final class MfaFlowKernelTest extends MfaKernelTestCase
{
    public function testWithoutMfaASignInIsUnchanged(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/home');
        $this->client->request('GET', '/mfa-home');
        self::assertSame('member@example.com|' . UsernamePasswordToken::class, $this->client->getResponse()->getContent());
    }

    public function testEnrolThenSignInWithACode(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        self::assertCount(1, $this->eventsOf(MfaEnabledEvent::class));
        self::assertNotSame('', $secret);
        $this->client->request('GET', '/logout');

        $this->client->request('GET', '/mfa-home');
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/login/mfa');

        // The pending sign-in reaches nothing else: pages redirect, JSON gets 401, roles are empty.
        $this->client->request('GET', '/mfa-home');
        self::assertResponseRedirects('/login/mfa');
        $this->client->request('GET', '/account/security/mfa', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('{"error":"mfa_required"}', $this->client->getResponse()->getContent());

        $this->challenge(['code' => '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->eventsOf(MfaChallengeFailedEvent::class));

        $this->challenge(['code' => $this->code($secret)]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/mfa-home', $this->client->getResponse()->headers->get('Location'), 'The original target is kept.');
        $this->client->request('GET', '/mfa-home');
        self::assertSame('member@example.com|' . UsernamePasswordToken::class, $this->client->getResponse()->getContent());
        self::assertCount(1, $this->eventsOf(MfaLoginCompletedEvent::class));
        self::assertSame('form_login', $this->eventsOf(MfaChallengePassedEvent::class)[0]->firstFactor);
        self::assertSame('totp', $this->eventsOf(MfaChallengePassedEvent::class)[0]->method);
    }

    public function testAUsedCodeIsRefusedAndARecoveryCodeWorksOnce(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret, $codes] = $this->enrol();
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        $code = $this->code($secret);
        $this->challenge(['code' => $code]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['code' => $code]);
        self::assertResponseStatusCodeSame(422, 'A code of an already used time step is refused (replay).');

        $this->challenge(['recovery_code' => strtolower($codes[0])]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(9, $this->eventsOf(MfaRecoveryCodeUsedEvent::class)[0]->remaining);
        $this->client->request('GET', '/logout');

        $this->signIn('member@example.com', 'member-password-123');
        $this->challenge(['recovery_code' => $codes[0]]);
        self::assertResponseStatusCodeSame(422, 'A recovery code works once.');
    }

    public function testRememberMeIsOnlyIssuedAfterTheSecondStep(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();

        $this->signIn('member@example.com', 'member-password-123', remember: true);
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'), 'No remember-me cookie for a half-authenticated user.');
        $this->challenge(['code' => $this->code($secret)]);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        // A remember-me sign-in follows a completed second step: not challenged again (mode trusted).
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $this->client->getResponse()->getContent());
    }
}
