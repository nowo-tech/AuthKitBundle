<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use Nowo\UiKitBundle\View\AuthPage;
use Nowo\UiKitBundle\View\Link;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

use function in_array;

/**
 * Sign-in page with availability checkers (1.37): the links and the template flags hosts read.
 */
final class LoginControllerAvailabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, ?bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        // mail ready, checker answer (null = none) => link shown, template flag
        yield 'no checker, mail ready (as before)' => [true, null, true, true];
        yield 'no checker, mail not ready (as before)' => [false, null, false, true];
        yield 'checker yes, mail ready' => [true, true, true, true];
        yield 'checker yes beats mail not ready (SMS reset)' => [false, true, true, true];
        yield 'checker no beats mail ready' => [true, false, false, false];
        yield 'checker no, mail not ready' => [false, false, false, false];
    }

    #[DataProvider('cases')]
    public function testLinksAndFlags(bool $mailReady, ?bool $answer, bool $linkShown, bool $flag): void
    {
        $context = $this->render($mailReady, $answer);

        $page = $context['page'];
        self::assertInstanceOf(AuthPage::class, $page);
        $hrefs = [];
        foreach ($page->links as $link) {
            self::assertInstanceOf(Link::class, $link);
            $hrefs[] = $link->href;
        }
        self::assertSame($linkShown, in_array('/nowo_auth_kit_magic_login_request', $hrefs, true));
        self::assertSame($linkShown, in_array('/nowo_auth_kit_reset_password_request', $hrefs, true));
        self::assertSame($flag, $context['magic_login_enabled']);
        self::assertSame($flag, $context['password_reset_enabled']);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(bool $mailReady, ?bool $answer): array
    {
        $overrides = ['magic_login' => ['mode' => 'enabled'], 'password_reset' => ['mode' => 'enabled'], 'registration_mode' => 'disabled'];
        $registry  = ProfileRegistryFactory::single(TestUser::class, $overrides);
        $forms     = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new LoginFormType($registry, new PasswordFieldTypeResolver())))
            ->getFormFactory();

        $captured = [];
        $twig     = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $context) use (&$captured): string {
            $captured = $context;

            return 'page';
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);
        $mail = $this->createStub(OutboundMailReadyCheckerInterface::class);
        $mail->method('isReady')->willReturn($mailReady);

        $availability = new FeatureAvailability();
        if ($answer !== null) {
            $checker = $this->createStub(FeatureAvailabilityCheckerInterface::class);
            $checker->method('isAvailable')->willReturn($answer);
            $availability = new FeatureAvailability(new ServiceLocator([
                'magic_login.default'    => static fn (): FeatureAvailabilityCheckerInterface => $checker,
                'password_reset.default' => static fn (): FeatureAvailabilityCheckerInterface => $checker,
            ]));
        }

        $controller = new LoginController(
            $twig,
            $forms,
            $this->createStub(AuthenticationUtils::class),
            $this->createStub(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($urls),
            new RegistrationGate($this->createStub(EntityManagerInterface::class), $registry),
            new PasswordResetGate($registry),
            new MagicLoginGate($registry),
            new SocialLoginGate($registry, $credentials),
            new QrLoginGate($registry),
            $credentials,
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
            $mail,
            availability: $availability,
        );
        $controller->login(new Request());

        return $captured;
    }
}
