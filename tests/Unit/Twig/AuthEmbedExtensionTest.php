<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Controller\AuthKitRoutesTrait;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use Nowo\AuthKitBundle\Tests\Unit\Support\PasswordFieldResolvers;
use Nowo\AuthKitBundle\Twig\AuthEmbedExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

final class AuthEmbedExtensionTest extends TestCase
{
    use AuthKitRoutesTrait;

    /**
     * @param array<string, mixed> $profileOverrides
     */
    private function createFactory(array $profileOverrides): AuthEmbedContextFactory
    {
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $profileOverrides);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType($profileRegistry, PasswordFieldResolvers::typeResolver()))
            ->addType(new RegistrationFormType(
                $profileRegistry,
                PasswordFieldResolvers::repeatedFieldBuilder(),
            ))
            ->getFormFactory();

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'always']),
        );

        return new AuthEmbedContextFactory(
            $formFactory,
            $this->createMock(AuthenticationUtils::class),
            $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            $gate,
            $profileRegistry,
        );
    }

    public function testReturnsEmptyWhenEmbedDisabled(): void
    {
        $factory = $this->createFactory([
            'embed' => [
                'mode'           => AuthEmbedMode::Disabled->value,
                'show_login'     => true,
                'show_register'  => true,
                'template'       => '@NowoAuthKitBundle/embed/dropdown.html.twig',
                'login_panel'    => '@NowoAuthKitBundle/embed/_login_panel.html.twig',
                'register_panel' => '@NowoAuthKitBundle/embed/_register_panel.html.twig',
                'authenticated'  => '@NowoAuthKitBundle/embed/_authenticated.html.twig',
            ],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $extension = new AuthEmbedExtension($factory, $twig);

        self::assertSame('', $extension->renderDropdown());
    }

    public function testRendersConfiguredTemplate(): void
    {
        $factory = $this->createFactory([
            'embed' => [
                'mode'           => AuthEmbedMode::Dropdown->value,
                'show_login'     => true,
                'show_register'  => true,
                'template'       => '@NowoAuthKitBundle/embed/dropdown.html.twig',
                'login_panel'    => '@NowoAuthKitBundle/embed/_login_panel.html.twig',
                'register_panel' => '@NowoAuthKitBundle/embed/_register_panel.html.twig',
                'authenticated'  => '@NowoAuthKitBundle/embed/_authenticated.html.twig',
            ],
        ]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoAuthKitBundle/embed/dropdown.html.twig',
                self::callback(static function (array $vars): bool {
                    return $vars['form_theme'] === 'form/theme.html.twig'
                        && $vars['show_login'] === true
                        && $vars['active_panel'] === 'login';
                }),
            )
            ->willReturn('<div class="dropdown">embed</div>');

        $extension = new AuthEmbedExtension($factory, $twig);

        self::assertSame(
            '<div class="dropdown">embed</div>',
            $extension->renderDropdown(['form_theme' => 'form/theme.html.twig']),
        );
    }
}
