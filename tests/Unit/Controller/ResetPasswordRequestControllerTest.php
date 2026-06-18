<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Controller\ResetPasswordRequestController;
use Nowo\AuthKitBundle\Form\ResetPasswordRequestFormType;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class ResetPasswordRequestControllerTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRedirectsWhenResetDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = $this->controller(new PasswordResetGate('disabled'), AuthKitTestUrlGenerator::fromMock($inner));

        $response = $controller->request(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testRendersRequestForm(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>reset</html>');

        $controller = $this->controller(new PasswordResetGate('enabled'), AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)), $twig);

        $response = $controller->request(new Request());

        self::assertSame('<html>reset</html>', $response->getContent());
    }

    private function controller(
        PasswordResetGate $gate,
        AuthKitUrlGenerator $urlGenerator,
        ?Environment $twig = null,
    ): ResetPasswordRequestController {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new NullPasswordResetNotifier(),
            $urlGenerator,
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'link',
        );

        return new ResetPasswordRequestController(
            $twig ?? $this->createMock(Environment::class),
            Forms::createFormFactoryBuilder()
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(new ResetPasswordRequestFormType('email'))
                ->getFormFactory(),
            $gate,
            $handler,
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            $urlGenerator,
            $this->templates(),
            $this->routes(),
            null,
        );
    }
}
