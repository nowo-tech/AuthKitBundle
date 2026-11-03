<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaAuthenticationSubscriber;
use Nowo\AuthKitBundle\Mfa\MfaCheck;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStatus;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\RecoveryCodes;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\Mfa\Security\MfaVoter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;

use function serialize;
use function str_repeat;
use function unserialize;

final class MfaModelTest extends TestCase
{
    public function testOwner(): void
    {
        self::assertSame('App\\User#7', (new MfaOwner('App\\User', '7'))->key());
        $this->expectException(InvalidArgumentException::class);
        new MfaOwner('App\\User', '');
    }

    public function testOwnerResolverWithoutDoctrine(): void
    {
        $resolver = new MfaOwnerResolver();
        self::assertSame(InMemoryUser::class . '#ana', $resolver->resolve(new InMemoryUser('ana', null))?->key());
        self::assertNull($resolver->resolve(new InMemoryUser(str_repeat('a', 200), null)));
        self::assertNull($resolver->resolve(new stdClass()));
    }

    public function testEntities(): void
    {
        $now        = new DateTimeImmutable('2026-11-03 10:00:00');
        $credential = new MfaCredential('default', 'App\\User', '7', $now);
        self::assertNull($credential->getId());
        self::assertSame('default', $credential->getProfile());
        self::assertSame('App\\User#7', $credential->owner()->key());
        self::assertFalse($credential->isEnabled());
        self::assertSame($now, $credential->getCreatedAt());
        $credential->enable('SECRET', 5, $now);
        self::assertTrue($credential->isEnabled());
        self::assertSame('SECRET', $credential->getTotpSecret());
        self::assertSame(5, $credential->getLastUsedStep());
        $credential->disable($now->modify('+1 day'), 'admin@example.com');
        self::assertSame('admin@example.com', $credential->getResetBy());
        self::assertEquals($now->modify('+1 day'), $credential->getResetAt());
        self::assertEquals($now->modify('+1 day'), $credential->getUpdatedAt());
        self::assertNull((new MfaRecoveryCode(1, 'hash', $now))->getId());
        self::assertNull((new MfaTrustedDevice('p', 1, 'h', 'Firefox', null, $now, $now, $now))->getId());
    }

    public function testStatusAndCheck(): void
    {
        $status = new MfaStatus('default', true, null, 2, [], false, null, 3);
        self::assertTrue($status->recoveryCodesLow());
        self::assertFalse($status->inGrace());
        self::assertTrue((new MfaStatus('default', false, null, 0, [], true, new DateTimeImmutable(), 3))->inGrace());
        self::assertTrue(MfaCheck::Totp->isValid());
        self::assertTrue(MfaCheck::Recovery->isValid());
        self::assertFalse(MfaCheck::Invalid->isValid());
        self::assertFalse(MfaCheck::LockedOut->isValid());
    }

    public function testRecoveryCodes(): void
    {
        $codes = (new RecoveryCodes('s'))->generate(12);
        self::assertCount(12, $codes);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/', $codes[0]);
        self::assertSame('0123456789', RecoveryCodes::normalize('o1234-5678 9'));
        self::assertSame('1111ABCDEF', RecoveryCodes::normalize('iLl1a-bcdef'));
        self::assertNull(RecoveryCodes::normalize('123456'));
        self::assertNull(RecoveryCodes::normalize('UUUUU-UUUUU'), 'U is not in the Crockford alphabet.');
        self::assertNotSame((new RecoveryCodes('a'))->hash('0123456789'), (new RecoveryCodes('b'))->hash('0123456789'));
    }

    public function testFirstFactor(): void
    {
        $request = new Request();
        self::assertSame('default', MfaFirstFactor::of(null));
        self::assertSame('default', MfaFirstFactor::of($request));
        MfaFirstFactor::mark($request, 'social');
        self::assertSame('social', MfaFirstFactor::of($request));
        self::assertSame('form_login', MfaFirstFactor::fromAuthenticator((new ReflectionClass(FormLoginAuthenticator::class))->newInstanceWithoutConstructor()));
        self::assertSame('login_link', MfaFirstFactor::fromAuthenticator((new ReflectionClass(LoginLinkAuthenticator::class))->newInstanceWithoutConstructor()));
        self::assertSame('remember_me', MfaFirstFactor::fromAuthenticator((new ReflectionClass(RememberMeAuthenticator::class))->newInstanceWithoutConstructor()));
        self::assertNull(MfaFirstFactor::fromAuthenticator($this->createStub(AuthenticatorInterface::class)));
        $wrapped = new class((new ReflectionClass(FormLoginAuthenticator::class))->newInstanceWithoutConstructor()) {
            public function __construct(private readonly object $inner)
            {
            }

            public function getAuthenticator(): object
            {
                return $this->inner;
            }
        };
        self::assertSame('form_login', MfaFirstFactor::fromAuthenticator($wrapped), 'Debug wrappers are unwrapped.');
    }

    public function testPendingTokenAndVoter(): void
    {
        $user  = new InMemoryUser('ana', null, ['ROLE_USER']);
        $inner = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $token = new MfaPendingToken($inner, 'main', 'default', 'form_login', MfaPendingToken::MODE_CHALLENGE, 100);
        self::assertSame([], $token->getRoleNames());
        self::assertSame('ana', $token->getUserIdentifier());
        self::assertSame($user, $token->getUser());
        self::assertSame('main', $token->firewall());
        self::assertSame('default', $token->profile());
        self::assertSame('form_login', $token->firstFactor());
        self::assertSame(100, $token->createdAt());
        self::assertStringContainsString('ana', (string) $token);
        self::assertTrue($token->getAttribute(MfaPendingToken::PENDING_ATTRIBUTE));
        $token->setAttribute(MfaPendingToken::PENDING_ATTRIBUTE, false);
        $token->setAttribute('x', 1);
        $token->setAttributes(['y' => 2]);
        self::assertSame([MfaPendingToken::PENDING_ATTRIBUTE => true, 'y' => 2], $token->getAttributes());
        self::assertTrue($token->hasAttribute('y'));
        self::assertNull($token->getAttribute('x'));
        $token->eraseCredentials();
        $token->setUser(new InMemoryUser('ana', null, ['ROLE_USER']));
        $token->requestRememberMe();
        $token->setTargetPath('/x');
        self::assertSame(1, $token->fail());
        $copy = unserialize(serialize($token));
        self::assertInstanceOf(MfaPendingToken::class, $copy);
        self::assertTrue($copy->wantsRememberMe());
        self::assertSame('/x', $copy->targetPath());
        self::assertSame(1, $copy->failures());
        self::assertSame(MfaPendingToken::MODE_CHALLENGE, $copy->mode());

        $voter = new MfaVoter();
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [MfaVoter::IS_MFA_IN_PROGRESS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($inner, null, ['ROLE_X', MfaVoter::IS_MFA_IN_PROGRESS]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_X']));
        self::assertTrue($voter->supportsAttribute(MfaVoter::IS_MFA_IN_PROGRESS));
        self::assertFalse($voter->supportsAttribute('ROLE_X'));
        self::assertTrue($voter->supportsType('mixed'));
    }

    public function testLocalTargets(): void
    {
        $request = Request::create('https://app.example.test/login');
        self::assertNull(MfaAuthenticationSubscriber::localTarget(null, $request));
        self::assertNull(MfaAuthenticationSubscriber::localTarget('', $request));
        self::assertNull(MfaAuthenticationSubscriber::localTarget('https://evil.example/x', $request));
        self::assertNull(MfaAuthenticationSubscriber::localTarget('//evil.example/x', $request));
        self::assertNull(MfaAuthenticationSubscriber::localTarget('relative', $request));
        self::assertSame('/home?a=1', MfaAuthenticationSubscriber::localTarget('https://app.example.test/home?a=1', $request));
        self::assertSame('/home', MfaAuthenticationSubscriber::localTarget('/home', $request));
    }

    public function testDeviceLabelsAndCookie(): void
    {
        self::assertSame('Firefox · Linux', MfaTrustedDevices::label('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'));
        self::assertSame('Edge · Windows', MfaTrustedDevices::label('Mozilla/5.0 (Windows NT 10.0) AppleWebKit Chrome/130 Safari/537 Edg/130'));
        self::assertSame('Chrome · Android', MfaTrustedDevices::label('Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile Safari/537'));
        self::assertSame('Safari · iOS', MfaTrustedDevices::label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'));
        self::assertSame('Safari · macOS', MfaTrustedDevices::label('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605'));
        self::assertSame('Browser', MfaTrustedDevices::label('curl/8'));

        $secure = Request::create('https://app.example.test/');
        self::assertSame('__Host-' . MfaTrustedDevices::COOKIE, MfaTrustedDevices::cookieName($secure));
        $response = new Response();
        MfaTrustedDevices::writeCookie($secure, $response);
        self::assertSame([], $response->headers->getCookies(), 'Nothing queued, nothing written.');
        $secure->attributes->set(MfaTrustedDevices::QUEUE_ATTRIBUTE, ['value' => 'abc', 'expires' => 2000000000]);
        MfaTrustedDevices::writeCookie($secure, $response);
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('__Host-' . MfaTrustedDevices::COOKIE, $cookie->getName());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
    }

    public function testStoreWithoutMapping(): void
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $this->expectException(LogicException::class);
        (new MfaStore($doctrine))->credential('default', new MfaOwner('App\\User', '1'));
    }
}
