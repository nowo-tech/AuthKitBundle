<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Controller\MfaAccountController;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaAuthenticationSubscriber;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaContainmentSubscriber;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Edge cases of the MFA services on the real container (1.34): users without an MFA profile, no-op calls, re-enrolment,
 * exempt first factors, the containment of targets and debug routes, the admin guard.
 */
final class MfaEdgeKernelTest extends MfaKernelTestCase
{
    public function testUsersWithoutAnMfaProfile(): void
    {
        $stranger = new InMemoryUser('ghost', null);
        self::assertNull($this->manager()->status($stranger));
        self::assertSame(MfaRequirement::Optional, $this->manager()->requirement($stranger));
        self::assertFalse($this->manager()->mustEnrolNow($stranger));
        self::assertSame(0, $this->manager()->forgetDevices($stranger));
        $devices = self::getContainer()->get(MfaTrustedDevices::class);
        self::assertInstanceOf(MfaTrustedDevices::class, $devices);
        self::assertFalse($devices->isTrusted(new Request(), $stranger));
        self::assertNull($devices->trust(new Request(), $stranger));

        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($stranger, 'main', ['ROLE_USER']));
        $controller = self::getContainer()->get(MfaAccountController::class);
        self::assertInstanceOf(MfaAccountController::class, $controller);
        $this->expectException(NotFoundHttpException::class);
        $controller->account(new Request());
    }

    public function testNoOpsAndReEnrolment(): void
    {
        $member = $this->user('member@example.com');
        self::assertSame(MfaCheck::Invalid, $this->manager()->check($member, '123456', null));
        $this->manager()->disable($member);
        self::assertSame([], $this->manager()->regenerateRecoveryCodes($member));

        $secret = $this->manager()->newSecret();
        self::assertNotNull($this->manager()->enable($member, $secret, $this->code($secret)));
        $this->nextStep();
        self::assertFalse($this->manager()->mustEnrolNow($this->user('admin@example.com')), 'Inside the grace period.');
        $other = $this->manager()->newSecret();
        self::assertNull($this->manager()->enable($member, $other, '000000'));
        self::assertNotNull($this->manager()->enable($member, $other, $this->code($other)), 'Re-enrolment replaces the secret.');
        self::assertSame($other, $this->secretOf('member@example.com'));

        $admin  = $this->user('admin@example.com');
        $secret = $this->manager()->newSecret();
        $this->manager()->enable($admin, $secret, $this->code($secret));
        self::assertFalse($this->manager()->mustEnrolNow($this->user('admin@example.com')), 'Enrolled.');
    }

    public function testExemptFactorsAndForeignTokensAreLeftAlone(): void
    {
        $member = $this->user('member@example.com');
        $secret = $this->manager()->newSecret();
        $this->manager()->enable($member, $secret, $this->code($secret));
        $subscriber = self::getContainer()->get(MfaAuthenticationSubscriber::class . '.main');
        self::assertInstanceOf(MfaAuthenticationSubscriber::class, $subscriber);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $request = new Request();
        MfaFirstFactor::mark($request, 'qr');
        $requests->push($request);

        $token = new UsernamePasswordToken($member, 'main', ['ROLE_USER']);
        $event = new AuthenticationTokenCreatedEvent($token, new SelfValidatingPassport(new UserBadge('member@example.com')));
        $subscriber->onTokenCreated($event);
        self::assertSame($token, $event->getAuthenticatedToken(), 'QR login is exempt.');

        $event = new AuthenticationTokenCreatedEvent(new NullToken(), new SelfValidatingPassport(new UserBadge('x')));
        $subscriber->onTokenCreated($event);
        self::assertInstanceOf(NullToken::class, $event->getAuthenticatedToken());
    }

    public function testContainmentKeepsGetTargetsAndLetsDebugRoutesThrough(): void
    {
        $member     = $this->user('member@example.com');
        $pending    = new MfaPendingToken(new UsernamePasswordToken($member, 'main', ['ROLE_USER']), 'main', 'default', 'social', MfaPendingToken::MODE_CHALLENGE, $this->clock()->now()->getTimestamp());
        $tokens     = self::getContainer()->get('security.token_storage');
        $subscriber = self::getContainer()->get(MfaContainmentSubscriber::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        self::assertInstanceOf(MfaContainmentSubscriber::class, $subscriber);
        $tokens->setToken($pending);
        $kernel = self::getContainer()->get('kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        $request = Request::create('/reports?page=2');
        $event   = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);
        self::assertSame('/login/mfa', $event->getResponse()?->headers->get('Location'));
        self::assertSame('/reports?page=2', $pending->targetPath());

        $debug = Request::create('/_wdt/1');
        $debug->attributes->set('_route', '_wdt');
        $event = new RequestEvent($kernel, $debug, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);
        self::assertNull($event->getResponse());

        $event = new RequestEvent($kernel, Request::create('/x'), HttpKernelInterface::SUB_REQUEST);
        $subscriber->onRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testTheAdminPageGuardsItself(): void
    {
        $this->client->request('GET', '/admin/mfa/member@example.com');
        self::assertResponseRedirects('http://localhost/login');

        $this->client->loginUser($this->user('member@example.com'));
        $this->client->request('GET', '/admin/mfa/admin@example.com');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->user('support@example.com'));
        $this->client->request('GET', '/mfa-home?_switch_user=member@example.com');
        $this->client->request('GET', '/admin/mfa/admin@example.com');
        self::assertResponseStatusCodeSame(403, 'No reset while impersonating.');
    }

    public function testAForgedStepUpIsAnInvalidCode(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        $this->client->request('POST', '/account/security/mfa/disable', ['mfa_disable' => ['code' => '123456', '_token' => 'forged']]);
        self::assertResponseRedirects('/account/security/mfa', 303);
        self::assertTrue($this->manager()->isEnabled($this->user('member@example.com')));
    }
}
