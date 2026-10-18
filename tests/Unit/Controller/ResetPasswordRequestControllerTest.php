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
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use Nowo\UiKitBundle\View\Link;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

use function array_map;

final class ResetPasswordRequestControllerTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRedirectsWhenResetDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = $this->controller(
            new PasswordResetGate(ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['mode' => 'disabled'],
            ])),
            AuthKitTestUrlGenerator::fromMock($inner),
        );

        $response = $controller->request(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testRendersRequestForm(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>reset</html>');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]);

        $controller = $this->controller(
            new PasswordResetGate($profileRegistry),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $twig,
            $profileRegistry,
        );

        $response = $controller->request(new Request());

        self::assertSame('<html>reset</html>', $response->getContent());
    }

    /**
     * delivery code|both: the request page links the code-entry page (before "Back to sign in"); link: it does not.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function deliveryLinks(): iterable
    {
        yield 'link' => ['link', ['/nowo_auth_kit_login']];
        yield 'code' => ['code', ['/nowo_auth_kit_reset_password_code', '/nowo_auth_kit_login']];
        yield 'both' => ['both', ['/nowo_auth_kit_reset_password_code', '/nowo_auth_kit_login']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('deliveryLinks')]
    public function testLinksTheCodePageWhenCodeDeliveryIsEnabled(string $delivery, array $expected): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $links = null;
        $twig  = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(static function (string $template, array $context) use (&$links): string {
            $links = array_map(static fn (Link $link): string => $link->href, [...$context['page']->links]);

            return '<html>reset</html>';
        });

        $overrides       = ['password_reset' => ['mode' => 'enabled', 'delivery' => $delivery]];
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $overrides);

        $controller = $this->controller(
            new PasswordResetGate($profileRegistry),
            AuthKitTestUrlGenerator::fromMock($inner),
            $twig,
            $profileRegistry,
            $overrides,
        );

        $controller->request(new Request());

        self::assertSame($expected, $links);
    }

    /**
     * @param array<string, mixed>|null $resolverOverrides
     */
    private function controller(
        PasswordResetGate $gate,
        AuthKitUrlGenerator $urlGenerator,
        ?Environment $twig = null,
        ?ProfileRegistry $profileRegistry = null,
        ?array $resolverOverrides = null,
    ): ResetPasswordRequestController {
        $profileRegistry ??= ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled', 'delivery' => 'link'],
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new NullPasswordResetNotifier(),
            $urlGenerator,
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        return new ResetPasswordRequestController(
            $twig ?? $this->createMock(Environment::class),
            Forms::createFormFactoryBuilder()
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(FormKitTestSupport::withMerger(new ResetPasswordRequestFormType($profileRegistry)))
                ->getFormFactory(),
            $gate,
            $handler,
            new TokenStorage(),
            $urlGenerator,
            ProfileRegistryFactory::requestResolver(TestUser::class, $resolverOverrides ?? [
                'password_reset' => ['mode' => 'enabled'],
            ]),
        );
    }
}
