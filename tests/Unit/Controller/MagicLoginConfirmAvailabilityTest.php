<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * A failed confirm must not send the user to a request page that answers 404.
 */
final class MagicLoginConfirmAvailabilityTest extends TestCase
{
    public function testFallsBackToTheSignInPageWhileTheRequestPageIsUnavailable(): void
    {
        self::assertSame('/nowo_auth_kit_login', $this->location(false));
    }

    public function testKeepsTheRequestPageOtherwise(): void
    {
        self::assertSame('/nowo_auth_kit_magic_login_request', $this->location(true));
    }

    public function testADisabledModeNeverShowsTheForm(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $overrides = ['magic_login' => ['mode' => 'disabled', 'confirm_interstitial' => true]];
        $twig      = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $controller = new MagicLoginConfirmController(
            $twig,
            $this->createStub(FormFactoryInterface::class),
            AuthKitTestUrlGenerator::fromMock($urls),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
            $this->createStub(Security::class),
            magicLoginGate: new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, $overrides)),
        );

        self::assertSame('/nowo_auth_kit_login', $controller->check(Request::create('/magic-login/check?user=u&expires=1&hash=h'))->headers->get('Location'));
        self::assertSame('/nowo_auth_kit_login', $controller->confirm(Request::create('/magic-login/confirm', 'POST'))->headers->get('Location'));
    }

    private function location(bool $available): ?string
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $checker = $this->createStub(FeatureAvailabilityCheckerInterface::class);
        $checker->method('isAvailable')->willReturn($available);

        $controller = new MagicLoginConfirmController(
            $this->createStub(Environment::class),
            $this->createStub(FormFactoryInterface::class),
            AuthKitTestUrlGenerator::fromMock($urls),
            ProfileRegistryFactory::requestResolver(TestUser::class, ['magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => false]]),
            $this->createStub(Security::class),
            availability: new FeatureAvailability(new ServiceLocator([
                'magic_login.default' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
            ])),
        );

        return $controller->check(Request::create('/magic-login/check'))->headers->get('Location');
    }
}
