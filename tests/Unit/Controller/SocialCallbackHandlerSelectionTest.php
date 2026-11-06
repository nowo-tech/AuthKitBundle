<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Which handler owns a callback, with social login switched off for the profile (the kernel test has it on).
 */
final class SocialCallbackHandlerSelectionTest extends TestCase
{
    public function testFirstSupportingHandlerGetsTheFailureOfADisabledFeatureWhenTheStateIsProven(): void
    {
        $skipped = $this->createMock(SocialCallbackHandlerInterface::class);
        $skipped->method('supports')->willReturn(false);
        $skipped->expects(self::never())->method('handleFailure');

        $owner = $this->createMock(SocialCallbackHandlerInterface::class);
        $owner->method('supports')->with(self::isInstanceOf(Request::class), 'google')->willReturn(true);
        $owner->expects(self::once())->method('handleFailure')
            ->with(self::isInstanceOf(Request::class), 'google', SocialCallbackFailure::Unavailable)
            ->willReturn(new Response('back to the invitation'));
        $owner->expects(self::never())->method('handle');

        $later = $this->createMock(SocialCallbackHandlerInterface::class);
        $later->expects(self::never())->method('supports');

        $request = $this->request();
        $store   = $this->store($request);
        $request->query->set('state', $store->issue('google'));

        self::assertSame('back to the invitation', $this->controller([$skipped, $owner, $later], $store)->check($request, 'google')->getContent());
    }

    public function testADisabledFeatureWithoutAProvenStateDoesNotReachTheHandler(): void
    {
        $owner = $this->createMock(SocialCallbackHandlerInterface::class);
        $owner->method('supports')->willReturn(true);
        $owner->expects(self::never())->method('handleFailure');

        $request = $this->request();

        self::assertSame('/login', $this->controller([$owner], $this->store($request))->check($request, 'google')->headers->get('Location'));
    }

    public function testNoHandlersKeepTheRedirect(): void
    {
        $request = $this->request();

        self::assertSame('/login', $this->controller([], $this->store($request))->check($request, 'google')->headers->get('Location'));
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function store(Request $request): SocialLoginStateStore
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new SocialLoginStateStore($stack);
    }

    /**
     * @param list<SocialCallbackHandlerInterface> $handlers
     */
    private function controller(array $handlers, SocialLoginStateStore $store): SocialLoginCheckController
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/login');

        return new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, ['social_login' => ['mode' => 'disabled']]), $credentials),
            $credentials,
            new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog()),
            $store,
            new SocialAccountLinker(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(SocialLoginAccountRepository::class),
                PropertyAccess::createPropertyAccessor(),
                $this->createStub(UserPasswordHasherInterface::class),
                new MockClock(),
            ),
            AuthKitTestUrlGenerator::fromMock($urls),
            ProfileRegistryFactory::requestResolver(TestUser::class, ['social_login' => ['mode' => 'disabled']]),
            $this->createStub(Security::class),
            callbackHandlers: $handlers,
        );
    }
}
