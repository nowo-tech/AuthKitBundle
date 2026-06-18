<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordCodeController;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

final class ResetPasswordCodeControllerTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRendersCodeForm(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>code</html>');

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);

        $completer = new PasswordResetCompleter(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
            $tokenManager,
            FieldConfigNormalizerFields::registration(),
        );

        $controller = new ResetPasswordCodeController(
            $twig,
            Forms::createFormFactoryBuilder()
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(new ResetPasswordCodeFormType('email', new PasswordFieldTypeResolver(), 6))
                ->getFormFactory(),
            new PasswordResetGate('enabled'),
            $tokenManager,
            $completer,
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $this->templates(),
            $this->routes(),
            null,
        );

        $response = $controller->complete(new Request());

        self::assertSame('<html>code</html>', $response->getContent());
    }
}
