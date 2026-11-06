<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Embed;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The embedded login panel is the other place that links to the reset page.
 */
final class AuthEmbedContextFactoryAvailabilityTest extends TestCase
{
    public function testResetLinkFollowsTheChecker(): void
    {
        self::assertTrue($this->passwordResetEnabled(null));
        self::assertTrue($this->passwordResetEnabled(true));
        self::assertFalse($this->passwordResetEnabled(false));
    }

    private function passwordResetEnabled(?bool $answer): bool
    {
        $availability = new FeatureAvailability();
        if ($answer !== null) {
            $checker = $this->createStub(FeatureAvailabilityCheckerInterface::class);
            $checker->method('isAvailable')->willReturn($answer);
            $availability = new FeatureAvailability(new ServiceLocator([
                'password_reset.default' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
            ]));
        }
        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'registration_mode' => 'disabled',
            'password_reset'    => ['mode' => 'enabled'],
            'embed'             => ['mode' => 'dropdown', 'show_login' => false, 'show_register' => false, 'template' => 't', 'login_panel' => 'l', 'register_panel' => 'r', 'authenticated' => 'a'],
        ]);
        $factory = new AuthEmbedContextFactory(
            $this->createStub(FormFactoryInterface::class),
            $this->createStub(AuthenticationUtils::class),
            $this->createStub(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($this->createStub(UrlGeneratorInterface::class)),
            new RegistrationGate($this->createStub(EntityManagerInterface::class), $registry),
            $registry,
            availability: $availability,
        );

        $context = $factory->create();
        self::assertNotNull($context);

        return $context->passwordResetEnabled;
    }
}
